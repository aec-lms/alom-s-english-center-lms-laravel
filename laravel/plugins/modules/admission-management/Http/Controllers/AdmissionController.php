<?php

declare(strict_types=1);

namespace Plugins\Modules\AdmissionManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\AdmissionManagement\Models\AdmissionApplication;
use Plugins\Modules\AdmissionManagement\Models\AdmissionDocument;
use Plugins\Modules\StudentManagement\Models\Student;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;

class AdmissionController extends Controller
{
    private const REVIEW_STATUSES = ['pending', 'screening', 'shortlisted', 'approved', 'rejected', 'waitlisted'];

    private const TRANSITIONS = [
        'pending' => ['screening', 'shortlisted', 'approved', 'rejected', 'waitlisted'],
        'screening' => ['shortlisted', 'approved', 'rejected', 'waitlisted'],
        'shortlisted' => ['approved', 'rejected', 'waitlisted'],
        'waitlisted' => ['screening', 'shortlisted', 'approved', 'rejected'],
        'approved' => [],
        'rejected' => [],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);

        $query = AdmissionApplication::query()
            ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->when($request->filled('status') && in_array($request->input('status'), self::REVIEW_STATUSES, true), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = trim((string) $request->input('q'));
                $q->where(function ($nested) use ($term): void {
                    $nested->where('applicant_name', 'like', '%' . $term . '%')
                        ->orWhere('email', 'like', '%' . $term . '%')
                        ->orWhere('phone', 'like', '%' . $term . '%')
                        ->orWhere('application_code', 'like', '%' . $term . '%')
                        ->orWhere('desired_course', 'like', '%' . $term . '%');
                });
            });

        $applications = $query->with(['documents' => fn ($q) => $q->latest()])->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'screening' THEN 1 WHEN 'shortlisted' THEN 2 WHEN 'waitlisted' THEN 3 WHEN 'approved' THEN 4 ELSE 5 END")
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $countsQuery = AdmissionApplication::query()
            ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id));
        $statusCounts = (clone $countsQuery)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return view('admission-management::index', [
            'applications' => $applications,
            'statusCounts' => $statusCounts,
            'statuses' => self::REVIEW_STATUSES,
            'transitions' => self::TRANSITIONS,
            'branches' => $this->branchIntegrationAvailable()
                ? DB::table('branches')->where('is_active', true)->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('id', $actor->branch_id))->orderBy('name')->get()
                : collect(),
            'studentConversionAvailable' => app(PluginManager::class)->isEnabled('student-management') && Schema::hasTable('students'),
            'staff' => \App\Models\User::query()
                ->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->whereIn('slug', ['admin', 'branch_manager', 'teacher']))
                ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))
                ->orderBy('name')
                ->get(['id', 'name', 'branch_id']),
        ]);
    }

    public function applyPublic(Request $request): RedirectResponse
    {
        abort_if(strtolower(trim((string) app(PluginManager::class)->getSetting('admission-management', 'intake_status', 'open'))) === 'closed', 403, 'Admission applications are currently closed.');

        $validated = $request->validate([
            'applicant_name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
            'guardian_phone' => ['nullable', 'string', 'max:40'],
            'desired_course' => ['required', 'string', 'max:160'],
            'preferred_batch' => ['nullable', 'string', 'max:80'],
            'previous_education' => ['nullable', 'string', 'max:160'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:2000'],
            'branch_id' => $this->branchRule(),
        ]);

        $validated['email'] = strtolower(trim($validated['email']));
        if (!$this->branchIntegrationAvailable()) $validated['branch_id'] = null;

        $app = DB::transaction(function () use ($validated): AdmissionApplication {
            return AdmissionApplication::query()->create($validated + [
                'application_code' => 'ADM-' . strtoupper(Str::random(10)),
                'application_source' => 'website',
                'status' => 'pending',
            ]);
        });

        return back()->with('status', "Admission application submitted! Tracking ID: {$app->application_code}");
    }


    public function trackForm(): View
    {
        return view('admission-management::track', ['application' => null, 'searched' => false]);
    }

    public function trackPublic(Request $request): View
    {
        $validated = $request->validate([
            'application_code' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email:rfc', 'max:160'],
        ]);

        $application = AdmissionApplication::query()
            ->where('application_code', strtoupper(trim($validated['application_code'])))
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($validated['email']))])
            ->first();

        return view('admission-management::track', [
            'application' => $application,
            'searched' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $validated = $request->validate([
            'applicant_name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
            'guardian_phone' => ['nullable', 'string', 'max:40'],
            'desired_course' => ['required', 'string', 'max:160'],
            'preferred_batch' => ['nullable', 'string', 'max:80'],
            'previous_education' => ['nullable', 'string', 'max:160'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:2000'],
            'branch_id' => $this->branchRule(),
        ]);

        $validated['email'] = strtolower(trim($validated['email']));
        if ($actor && $actor->hasRole('branch_manager')) {
            $validated['branch_id'] = $actor->branch_id;
        } elseif (!$this->branchIntegrationAvailable()) {
            $validated['branch_id'] = null;
        }

        $app = DB::transaction(function () use ($validated, $actor): AdmissionApplication {
            return AdmissionApplication::query()->create($validated + [
                'application_code' => 'ADM-' . strtoupper(Str::random(10)),
                'application_source' => 'walk_in',
                'created_by' => $actor?->id,
                'status' => 'pending',
            ]);
        });

        $this->audit->log('admission.created', 'admission_application', (string) $app->id, [
            'branch_id' => $app->branch_id,
            'source' => $app->application_source,
        ]);

        return back()->with('status', "Application '{$app->application_code}' logged.");
    }

    public function review(Request $request, AdmissionApplication $application): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $validated = $request->validate([
            'status' => ['required', 'in:' . implode(',', self::REVIEW_STATUSES)],
            'reviewer_notes' => [$request->input('status') === 'rejected' ? 'required' : 'nullable', 'string', 'min:5', 'max:2000'],
            'interview_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        [$updated, $fromStatus] = DB::transaction(function () use ($application, $actor, $validated): array {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            $this->assertApplicationBranchAccess($actor, $locked);

            $current = (string) $locked->status;
            $next = (string) $validated['status'];
            abort_unless(in_array($next, self::TRANSITIONS[$current] ?? [], true), 422, "Invalid admission status transition: {$current} → {$next}.");

            if (!empty($validated['assigned_to'])) {
                $assignee = \App\Models\User::query()
                    ->whereKey($validated['assigned_to'])
                    ->where('is_active', true)
                    ->whereHas('roles', fn ($q) => $q->whereIn('slug', ['admin', 'branch_manager', 'teacher']))
                    ->firstOrFail();
                if ($actor && $actor->hasRole('branch_manager')) {
                    abort_unless((int) $assignee->branch_id === (int) $actor->branch_id, 403, 'Cannot assign an application outside your branch.');
                }
            }

            $locked->update([
                'status' => $next,
                'reviewer_notes' => $validated['reviewer_notes'] ?? $locked->reviewer_notes,
                'reviewed_by' => $actor?->id,
                'reviewed_at' => now(),
                'interview_at' => array_key_exists('interview_at', $validated) ? $validated['interview_at'] : $locked->interview_at,
                'assigned_to' => array_key_exists('assigned_to', $validated) ? $validated['assigned_to'] : $locked->assigned_to,
            ]);

            return [$locked->fresh(), $current];
        }, 3);

        $this->audit->log('admission.reviewed', 'admission_application', (string) $updated->id, [
            'from' => $fromStatus,
            'to' => $updated->status,
            'assigned_to' => $updated->assigned_to,
        ]);

        return back()->with('status', "Application {$updated->application_code} moved to {$updated->status}.");
    }



    public function storeDocument(Request $request, AdmissionApplication $application): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $this->assertApplicationBranchAccess($actor, $application);
        abort_unless(Schema::hasTable('admission_documents'), 503, 'Admission document storage is not installed.');

        abort_if($application->documents()->count() >= 10, 422, 'An application can have at most 10 supporting documents.');
        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $file = $validated['document'];
        abort_if((int) $application->documents()->sum('size_bytes') + (int) $file->getSize() > 51200 * 1024, 422, 'The application document storage limit is 50 MB.');
        $extension = strtolower((string) $file->extension());
        abort_unless(in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true), 422, 'Unsupported document format.');

        $filename = (string) Str::uuid() . '.' . $extension;
        $storedPath = $file->storeAs('admission-documents/' . $application->id, $filename, 'local');
        if (!$storedPath) {
            abort(500, 'The admission document could not be stored.');
        }

        try {
            $document = AdmissionDocument::query()->create([
                'admission_application_id' => $application->id,
                'uploaded_by' => $actor?->id,
                'disk' => 'local',
                'path' => $storedPath,
                'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                'mime_type' => (string) $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedPath);
            throw $exception;
        }

        $this->audit->log('admission.document_uploaded', 'admission_document', (string) $document->id, [
            'application_id' => $application->id,
            'size_bytes' => $document->size_bytes,
            'mime_type' => $document->mime_type,
        ]);

        return back()->with('status', 'Admission document uploaded securely.');
    }

    public function downloadDocument(Request $request, AdmissionApplication $application, AdmissionDocument $document)
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $this->assertApplicationBranchAccess($actor, $application);
        abort_unless((int) $document->admission_application_id === (int) $application->id, 404);
        abort_unless($document->disk === 'local', 404);
        abort_unless(Str::startsWith($document->path, 'admission-documents/' . $application->id . '/')
            && !str_contains($document->path, '..') && !str_contains($document->path, chr(92)), 404);
        abort_unless(Storage::disk('local')->exists($document->path), 404);
        $storedFile = Storage::disk('local')->path($document->path);
        abort_unless(hash_equals((string) $document->sha256, (string) hash_file('sha256', $storedFile)), 410, 'The stored document failed its integrity check.');

        $this->audit->log('admission.document_downloaded', 'admission_document', (string) $document->id, [
            'application_id' => $application->id,
        ]);

        return Storage::disk('local')->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function convertToStudent(Request $request, AdmissionApplication $application): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $this->assertApplicationBranchAccess($actor, $application);

        abort_unless(app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasTable('roles')
            && Schema::hasTable('role_user'), 503, 'Student Management and its role integration must be enabled first.');

        $createdAccount = false;
        $student = DB::transaction(function () use ($application, &$createdAccount): Student {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless($locked->status === 'approved', 422, 'Only approved applicants can be converted to students.');

            if ($locked->converted_student_id) {
                return Student::query()->findOrFail($locked->converted_student_id);
            }

            $studentRole = Role::query()->where('slug', 'student')->first();
            abort_unless($studentRole, 503, 'The Student role is not configured.');

            $email = strtolower(trim((string) $locked->email));
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();

            if ($user && !$user->hasRole('student')) {
                abort(409, 'This email already belongs to a non-student account. Resolve the account ownership before conversion.');
            }
            if ($user && $locked->branch_id !== null && $user->branch_id !== null
                && (int) $user->branch_id !== (int) $locked->branch_id) {
                abort(409, 'The existing student account belongs to another branch. Resolve branch ownership before conversion.');
            }

            if (!$user) {
                $user = User::query()->create([
                    'name' => $locked->applicant_name,
                    'email' => $email,
                    'phone' => $locked->phone,
                    'branch_id' => $locked->branch_id,
                    'password' => Str::random(64),
                    'is_active' => true,
                ]);
                $createdAccount = true;
            } elseif ($user->branch_id === null && $locked->branch_id !== null) {
                $user->update(['branch_id' => $locked->branch_id]);
            }

            $user->roles()->syncWithoutDetaching([$studentRole->id]);

            $existingStudent = Student::withTrashed()->where('user_id', $user->id)->first();
            abort_if($existingStudent && $existingStudent->trashed(), 409, 'This account has a student profile in the recycle bin. Restore it before linking a new admission.');
            $student = $existingStudent;
            if (!$student) {
                $studentCode = 'STU-' . strtoupper(Str::random(10));
                while (Student::query()->where('student_code', $studentCode)->exists()) {
                    $studentCode = 'STU-' . strtoupper(Str::random(10));
                }

                $student = Student::query()->create([
                    'user_id' => $user->id,
                    'branch_id' => $locked->branch_id ?? $user->branch_id,
                    'student_code' => $studentCode,
                    'admission_date' => now()->toDateString(),
                    'status' => 'active',
                ]);
            }

            $feeSetting = app(PluginManager::class)->getSetting('admission-management', 'admission_fee_bdt', 0);
            abort_unless(is_numeric($feeSetting), 422, 'The configured admission fee is invalid.');
            $admissionFee = round((float) $feeSetting, 2);
            abort_if($admissionFee < 0 || $admissionFee > 10000000, 422, 'The configured admission fee is invalid.');

            $feeInvoiceId = $locked->admission_fee_invoice_id;
            if ($admissionFee > 0 && !$feeInvoiceId) {
                abort_unless(app(PluginManager::class)->isEnabled('fees-management')
                    && Schema::hasTable('fee_invoices')
                    && Schema::hasColumn('fee_invoices', 'student_id')
                    && Schema::hasColumn('fee_invoices', 'branch_id')
                    && Schema::hasColumn('fee_invoices', 'payer_name')
                    && Schema::hasColumn('fee_invoices', 'payer_email')
                    && Schema::hasColumn('fee_invoices', 'payer_phone'), 503, 'Enable and migrate Fees & Invoicing before issuing an admission fee.');

                $invoice = FeeInvoice::query()->create([
                    'invoice_number' => 'ADM-' . strtoupper(Str::random(12)),
                    'student_id' => $student->id,
                    'payer_name' => $user->name,
                    'payer_email' => $user->email,
                    'payer_phone' => $user->phone,
                    'branch_id' => $locked->branch_id,
                    'subtotal_amount' => $admissionFee,
                    'discount_amount' => 0,
                    'net_amount' => $admissionFee,
                    'paid_amount' => 0,
                    'currency' => strtoupper((string) config('lms.default_currency', 'BDT')),
                    'status' => 'unpaid',
                    'due_date' => now()->addDays(7)->toDateString(),
                ]);
                $feeInvoiceId = $invoice->id;
            }

            $locked->update([
                'converted_student_id' => $student->id,
                'converted_at' => now(),
                'admission_fee_invoice_id' => $feeInvoiceId,
            ]);

            return $student;
        }, 3);

        $resetLinkStatus = null;
        if ($createdAccount) {
            try {
                $resetLinkStatus = Password::sendResetLink(['email' => strtolower(trim((string) $application->email))]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $this->audit->log('admission.converted_to_student', 'admission_application', (string) $application->id, [
            'student_id' => $student->id,
            'created_account' => $createdAccount,
            'password_reset_notification' => $resetLinkStatus,
        ]);

        return back()->with('status', $createdAccount
            ? 'Applicant converted to student. A password setup email has been requested; confirm mail delivery before sharing login instructions.'
            : 'Applicant linked to the existing student account successfully.');
    }

    private function branchIntegrationAvailable(): bool
    {
        return app(PluginManager::class)->isEnabled('branch-management') && Schema::hasTable('branches');
    }

    private function branchRule(): array
    {
        return $this->branchIntegrationAvailable()
            ? ['nullable', 'integer', 'exists:branches,id,is_active,1']
            : ['nullable', 'integer'];
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) return;

        if ($actor->branch_id === null || !$this->branchIntegrationAvailable()) {
            abort(403, 'Branch manager must be assigned to a branch.');
        }

        $active = DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists();
        abort_unless($active, 403, 'Branch manager access is disabled because the assigned branch is inactive.');
    }

    private function assertApplicationBranchAccess($actor, AdmissionApplication $application): void
    {
        if ($actor && $actor->hasRole('branch_manager')
            && (int) $application->branch_id !== (int) $actor->branch_id) {
            abort(403, 'Branch isolation violation.');
        }
    }
}
