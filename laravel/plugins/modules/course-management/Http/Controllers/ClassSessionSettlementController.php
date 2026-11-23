<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Core\Security\BranchAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\CourseManagement\Models\LiveClass;

class ClassSessionSettlementController extends Controller
{
    public function __construct(private readonly BranchAccess $branches, private readonly AuditLogger $audit) {}

    public function complete(Request $request, LiveClass $liveClass): RedirectResponse
    {
        $actor = $request->user();
        $this->branches->assertRecord($actor, (object) ['branch_id' => $liveClass->course?->branch_id]);
        abort_unless(Schema::hasTable('class_session_records'), 409, 'Class settlement ledger is not installed.');

        $session = DB::transaction(function () use ($liveClass, $actor) {
            $lockedClass = LiveClass::query()->whereKey($liveClass->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($lockedClass->status, ['upcoming', 'live'], true), 409, 'Only a scheduled or live class can be completed.');
            $course = DB::table('courses')->where('id', $lockedClass->course_id)->first();
            abort_unless($course, 404, 'Course not found.');
            $teacherId = (int) ($lockedClass->teacher_user_id ?? 0);
            abort_if($teacherId < 1, 422, 'Assign a teacher to this live class before completing it.');
            abort_unless(DB::table('course_teacher_assignments')->where('course_id', $lockedClass->course_id)->where('teacher_user_id', $teacherId)->exists(), 422, 'The assigned teacher is no longer assigned to this course.');
            abort_if(DB::table('class_session_records')->where('live_class_id', $lockedClass->id)->lockForUpdate()->exists(), 409, 'This class session has already been settled.');

            $teacherRate = round((float) $lockedClass->teacher_rate_per_class, 2);
            $studentRate = round((float) $lockedClass->student_fee_per_class, 2);
            $enrollments = collect();
            if ($studentRate > 0) {
                abort_unless(app(PluginManager::class)->isEnabled('student-management') && Schema::hasTable('students') && Schema::hasTable('enrollments') && Schema::hasTable('fee_invoices'), 409, 'Student Management, Course Management and Fees Management must be enabled for per-class student billing.');
                $enrollments = DB::table('enrollments')
                    ->join('students', 'students.user_id', '=', 'enrollments.user_id')
                    ->join('users', 'users.id', '=', 'enrollments.user_id')
                    ->where('enrollments.course_id', $lockedClass->course_id)
                    ->where('enrollments.status', 'active')
                    ->where('students.status', 'active')
                    ->when($course->branch_id !== null, fn ($q) => $q->where(function ($b) use ($course) { $b->whereNull('enrollments.branch_id')->orWhere('enrollments.branch_id', $course->branch_id); })
                        ->where(function ($b) use ($course) { $b->whereNull('students.branch_id')->orWhere('students.branch_id', $course->branch_id); }))
                    ->select('enrollments.user_id', 'students.id as student_id', 'users.name', 'users.email')
                    ->lockForUpdate()->get();
            }

            $sessionId = DB::table('class_session_records')->insertGetId([
                'live_class_id' => $lockedClass->id, 'course_id' => $lockedClass->course_id,
                'branch_id' => $course->branch_id, 'teacher_user_id' => $teacherId,
                'completed_by' => $actor->id, 'completed_at' => now(),
                'teacher_rate' => $teacherRate, 'student_fee_rate' => $studentRate,
                'enrolled_student_count' => $enrollments->count(),
                'student_fees_total' => round($studentRate * $enrollments->count(), 2),
                'status' => 'completed', 'completion_notes' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($enrollments as $enrollment) {
                $invoiceId = DB::table('fee_invoices')->insertGetId([
                    'invoice_number' => 'CLS-' . strtoupper(bin2hex(random_bytes(5))),
                    'student_id' => $enrollment->student_id, 'payer_name' => $enrollment->name,
                    'payer_email' => $enrollment->email, 'branch_id' => $course->branch_id,
                    'fee_category_id' => null, 'subtotal_amount' => $studentRate,
                    'discount_amount' => 0, 'net_amount' => $studentRate, 'paid_amount' => 0,
                    'currency' => strtoupper((string) config('lms.default_currency', 'BDT')),
                    'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('class_session_student_charges')->insert([
                    'session_record_id' => $sessionId, 'user_id' => $enrollment->user_id,
                    'student_id' => $enrollment->student_id, 'fee_invoice_id' => $invoiceId,
                    'amount' => $studentRate, 'status' => 'invoiced',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            if ($teacherRate > 0) {
                DB::table('teacher_class_payments')->insert([
                    'session_record_id' => $sessionId, 'teacher_user_id' => $teacherId,
                    'branch_id' => $course->branch_id, 'amount' => $teacherRate,
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $lockedClass->update(['status' => 'completed']);
            return DB::table('class_session_records')->where('id', $sessionId)->first();
        });

        $this->audit->log('class_session.completed_and_settled', 'class_session_record', (string) $session->id, [
            'live_class_id' => $liveClass->id, 'teacher_user_id' => $session->teacher_user_id,
            'teacher_payable' => $session->teacher_rate, 'student_invoices_total' => $session->student_fees_total,
            'enrolled_student_count' => $session->enrolled_student_count,
        ]);
        return back()->with('status', 'Class completed. Teacher payable and enrolled-student fee invoices have been recorded exactly once.');
    }

    public function markTeacherPaid(Request $request, int $payment): RedirectResponse
    {
        $actor = $request->user();
        $data = $request->validate(['payment_reference' => ['nullable', 'string', 'max:100']]);
        $record = DB::table('teacher_class_payments')->where('id', $payment)->first();
        abort_unless($record, 404);
        $this->branches->assertRecord($actor, (object) ['branch_id' => $record->branch_id]);
        DB::transaction(function () use ($record, $actor, $data): void {
            $locked = DB::table('teacher_class_payments')->where('id', $record->id)->lockForUpdate()->first();
            abort_unless($locked, 404);
            abort_if($locked->status !== 'pending', 409, 'Teacher class payment is already settled.');
            DB::table('teacher_class_payments')->where('id', $locked->id)->update([
                'status' => 'paid', 'paid_by' => $actor->id, 'paid_at' => now(),
                'payment_reference' => $data['payment_reference'] ?? null, 'updated_at' => now(),
            ]);
        });
        $this->audit->log('teacher.class_payment_paid', 'teacher_class_payment', (string) $payment, [
            'teacher_user_id' => $record->teacher_user_id, 'amount' => $record->amount,
            'payment_reference' => $data['payment_reference'] ?? null,
        ]);
        return back()->with('status', 'Teacher class payment marked as paid.');
    }
}
