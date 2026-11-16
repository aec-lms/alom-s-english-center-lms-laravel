<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\Enrollment;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\StudentManagement\Models\Student;
use RuntimeException;

class EnrollmentController extends Controller
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function my(Request $request): View
    {
        $query = Enrollment::query()
            ->with('course')
            ->where('user_id', $request->user()->id);
        $feesIntegrationAvailable = $this->feesIntegrationAvailable();
        if ($feesIntegrationAvailable) {
            $query->with('invoice');
        }
        $enrollments = $query->latest()->paginate(20);

        return view('course-management::my-enrollments', compact('enrollments', 'feesIntegrationAvailable'));
    }

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $query = Enrollment::query()
            ->with(['user', 'course'])
            ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id));
        $feesIntegrationAvailable = $this->feesIntegrationAvailable();
        if ($feesIntegrationAvailable) {
            $query->with('invoice');
        }
        $enrollments = $query->latest()->paginate(30);

        return view('course-management::enrollments', compact('enrollments', 'feesIntegrationAvailable'));
    }

    public function store(Request $request, Course $course): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('student'), 403, 'Only student accounts can request course enrollment.');
        abort_unless($course->status === 'published', 404, 'This course is not open for enrollment.');

        $studentIntegration = $this->plugins->isEnabled('student-management') && Schema::hasTable('students');
        $student = $studentIntegration ? Student::query()->where('user_id', $actor->id)->first() : null;
        if ($studentIntegration) {
            abort_unless($student, 422, 'Your student profile is not ready. Contact the admissions office.');
        }
        $learnerBranchId = $student?->branch_id ?? $actor->branch_id;
        if ($course->branch_id !== null && (int) $course->branch_id !== (int) $learnerBranchId) {
            abort(403, 'This course is not available in your assigned branch.');
        }

        $existing = Enrollment::query()->where('user_id', $actor->id)->where('course_id', $course->id)->first();
        if ($existing && in_array($existing->status, ['pending', 'active'], true)) {
            return back()->with('status', 'You already have an enrollment request for this course.');
        }
        if ($existing) {
            abort(409, 'This course enrollment was previously closed. Contact the admissions office to reopen it.');
        }

        $price = round((float) $course->price, 2);
        $paidCourse = $price > 0;
        if ($paidCourse && (!$this->plugins->isEnabled('fees-management') || !Schema::hasTable('fee_invoices'))) {
            abort(503, 'The Fees & Invoicing plugin must be enabled before paid course enrollment can be opened.');
        }

        DB::transaction(function () use ($actor, $student, $learnerBranchId, $course, $price, $paidCourse): void {
            $invoice = null;
            $status = 'active';

            if ($paidCourse) {
                $invoice = FeeInvoice::query()->create([
                    'invoice_number' => 'ENR-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)),
                    'student_id' => $student?->id,
                    'payer_name' => $student ? null : $actor->name,
                    'payer_email' => $student ? null : $actor->email,
                    'payer_phone' => $student ? null : $actor->phone,
                    'branch_id' => $learnerBranchId,
                    'subtotal_amount' => $price,
                    'discount_amount' => 0,
                    'net_amount' => $price,
                    'paid_amount' => 0,
                    'currency' => 'BDT',
                    'status' => 'unpaid',
                    'due_date' => now()->addDays(7)->toDateString(),
                ]);
                $status = 'pending';
            }

            Enrollment::query()->create([
                'user_id' => $actor->id,
                'course_id' => $course->id,
                'branch_id' => $learnerBranchId,
                'invoice_id' => $invoice?->id,
                'status' => $status,
            ]);
        });

        if ($this->plugins->isEnabled('student-tracking')) {
            app(StudentActivityTracker::class)->record((int) $actor->id, 'course_enrolled', 'course', (int) $course->id, null, ['course_title' => $course->title]);
        }

        return back()->with('status', $paidCourse
            ? 'Enrollment requested. Pay the generated invoice to activate course access.'
            : 'You are enrolled in this free course.');
    }

    public function updateStatus(Request $request, Enrollment $enrollment): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $enrollment->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        $validated = $request->validate(['status' => ['required', 'in:pending,active,cancelled,completed']]);
        if ($validated['status'] === 'active' && (float) $enrollment->course?->price > 0) {
            abort_unless($this->feesIntegrationAvailable(), 503, 'Fees Management must be enabled before activating a paid course enrollment.');
            $invoice = $enrollment->invoice;
            if (!$invoice || $invoice->status !== 'paid' || (float) $invoice->paid_amount + 0.01 < (float) $invoice->net_amount) {
                abort(422, 'Paid course enrollment cannot be activated until its linked invoice is fully settled.');
            }
        }

        $enrollment->update(['status' => $validated['status']]);

        return back()->with('status', 'Enrollment status updated.');
    }

    private function feesIntegrationAvailable(): bool
    {
        return $this->plugins->isEnabled('fees-management') && Schema::hasTable('fee_invoices');
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }
        if ($actor->branch_id === null) {
            abort(403, 'Branch manager must be assigned to a branch.');
        }
        $activeBranch = app('db')->table('branches')
            ->where('id', $actor->branch_id)
            ->where('is_active', true)
            ->exists();
        if (!$activeBranch) {
            abort(403, 'Branch manager access is disabled because the assigned branch is inactive.');
        }
    }
}
