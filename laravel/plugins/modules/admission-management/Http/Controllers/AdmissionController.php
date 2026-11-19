<?php

declare(strict_types=1);

namespace Plugins\Modules\AdmissionManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\AdmissionManagement\Models\AdmissionApplication;

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

        $applications = $query->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'screening' THEN 1 WHEN 'shortlisted' THEN 2 WHEN 'waitlisted' THEN 3 WHEN 'approved' THEN 4 ELSE 5 END")
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
        ]);
    }

    public function applyPublic(Request $request): RedirectResponse
    {
        abort_if(app(PluginManager::class)->getSetting('admission-management', 'intake_status', 'open') === 'closed', 403, 'Admission applications are currently closed.');

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
            ->where('email', strtolower(trim($validated['email'])))
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

        $updated = DB::transaction(function () use ($application, $actor, $validated): AdmissionApplication {
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
                'assigned_to' => $validated['assigned_to'] ?? $locked->assigned_to,
            ]);

            return $locked->fresh();
        }, 3);

        $this->audit->log('admission.reviewed', 'admission_application', (string) $updated->id, [
            'from' => $application->status,
            'to' => $updated->status,
            'assigned_to' => $updated->assigned_to,
        ]);

        return back()->with('status', "Application {$updated->application_code} moved to {$updated->status}.");
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
