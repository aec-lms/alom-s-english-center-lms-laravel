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
use RuntimeException;

class EnrollmentController extends Controller
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function my(Request $request): View
    {
        $enrollments = Enrollment::query()
            ->with(['course.liveClasses', 'invoice'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        $feesIntegrationAvailable = $this->plugins->isEnabled('fees-management') && Schema::hasTable('fee_invoices');

        return view('course-management::my-enrollments', compact('enrollments', 'feesIntegrationAvailable'));
    }

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $enrollments = Enrollment::query()
            ->with(['user', 'course', 'invoice'])
            ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->latest()
            ->paginate(30);

        $feesIntegrationAvailable = $this->plugins->isEnabled('fees-management') && Schema::hasTable('fee_invoices');

        return view('course-management::enrollments', compact('enrollments', 'feesIntegrationAvailable'));
    }

    public function store(Request $request, Course $course): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        abort_unless($actor && $actor->hasRole('student'), 403, 'Only student accounts can request course enrollment.');
        abort_unless($course->status === 'published', 404, 'This course is not open for enrollment.');

        $studentIntegrationEnabled = $this->plugins->isEnabled('student-management') && Schema::hasTable('students');
        $student = $studentIntegrationEnabled
            ? DB::table('students')->where('user_id', $actor->id)->first()
            : null;
        if ($studentIntegrationEnabled) {
            abort_unless($student, 422, 'Your student profile is not ready. Contact the admissions office.');
        }
        $branchId = $student->branch_id ?? $actor->branch_id ?? $course->branch_id;
        if ($course->branch_id !== null && (int) $course->branch_id !== (int) $branchId) {
            abort(403, 'This course is not available in your assigned branch.');
        }
        if ($actor->hasRole('branch_manager') && (int) ($actor->branch_id ?? 0) !== (int) ($course->branch_id ?? $actor->branch_id ?? 0)) {
            abort(403, 'This course is outside your assigned branch.');
        }

        $existing = Enrollment::query()->where('user_id', $actor->id)->where('course_id', $course->id)->first();
        if ($existing && in_array($existing->status, ['pending', 'active'], true)) {
            return back()->with('status', 'You already have an enrollment request for this course.');
        }
        if ($existing) {
            abort(409, 'This course enrollment was previously closed. Contact the admissions office to reopen it.');
        }

        $originalPrice = round((float) $course->price, 2);
        $price = $originalPrice;
        if ($this->plugins->isEnabled('flash-sale') && Schema::hasTable('flash_sales')) {
            $sale = DB::table('flash_sales')
                ->where('course_id', $course->id)
                ->where('is_published', true)
                ->where('is_active', true)
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>', now())
                ->orderByDesc('id')
                ->first();
            if ($sale) {
                $discount = $sale->discount_type === 'percent'
                    ? $originalPrice * ((float) $sale->discount_value / 100)
                    : (float) $sale->discount_value;
                $price = round(max(0, $originalPrice - min($originalPrice, $discount)), 2);
            }
        }
        $paidCourse = $price > 0;
        if ($paidCourse && (!$this->plugins->isEnabled('fees-management') || !Schema::hasTable('fee_invoices'))) {
            abort(503, 'The Fees & Invoicing plugin must be enabled before paid course enrollment can be opened.');
        }

        DB::transaction(function () use ($actor, $student, $branchId, $course, $originalPrice, $price, $paidCourse): void {
            $invoice = null;
            $status = 'active';

            if ($paidCourse) {
                $invoice = FeeInvoice::query()->create([
                    'invoice_number' => 'ENR-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)),
                    'student_id' => $student->id ?? null,
                    'payer_name' => $student->id ?? null ? null : $actor->name,
                    'payer_email' => $student->id ?? null ? null : $actor->email,
                    'payer_phone' => $student->id ?? null ? null : ($actor->phone ?? null),
                    'branch_id' => $branchId,
                    'subtotal_amount' => $originalPrice,
                    'discount_amount' => round($originalPrice - $price, 2),
                    'net_amount' => $price,
                    'paid_amount' => 0,
                    'currency' => strtoupper((string) config('lms.default_currency', 'BDT')),
                    'status' => 'unpaid',
                    'due_date' => now()->addDays(7)->toDateString(),
                ]);
                $status = 'pending';
            }

            Enrollment::query()->create([
                'user_id' => $actor->id,
                'course_id' => $course->id,
                'branch_id' => $branchId,
                'invoice_id' => $invoice?->id,
                'status' => $status,
            ]);
        });

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
            $invoice = $enrollment->invoice;
            if (!$invoice || $invoice->status !== 'paid' || (float) $invoice->paid_amount + 0.01 < (float) $invoice->net_amount) {
                abort(422, 'Paid course enrollment cannot be activated until its linked invoice is fully settled.');
            }
        }

        $enrollment->update(['status' => $validated['status']]);

        return back()->with('status', 'Enrollment status updated.');
    }
    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }

        abort_unless(
            $actor->branch_id !== null
                && $this->plugins->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')
                    ->where('id', $actor->branch_id)
                    ->where('is_active', true)
                    ->exists(),
            403,
            'Branch manager must be assigned to an active branch.'
        );
    }

}
