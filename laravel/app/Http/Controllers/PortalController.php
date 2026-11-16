<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($user->hasRole('admin')) {
            return redirect()->route('admin.dashboard');
        }

        $role = collect(['student', 'teacher', 'guardian', 'branch_manager', 'accountant'])
            ->first(fn (string $candidate) => $user->hasRole($candidate)) ?? 'user';
        $branchId = $user->branch_id;
        if ($role === 'branch_manager' && $branchId === null) abort(403, 'Branch manager account has no assigned branch.');
        $student = null;
        $teacher = null;
        $guardian = null;
        $enrollments = collect();
        $invoices = collect();
        $attendance = collect();
        $attempts = collect();
        $courses = collect();
        $liveClasses = collect();
        $children = collect();
        $metrics = [];

        if ($role === 'student') {
            $student = Schema::hasTable('students')
                ? DB::table('students')->where('user_id', $user->id)->first()
                : null;
            if ($student && Schema::hasTable('enrollments') && Schema::hasTable('courses')) {
                $enrollments = DB::table('enrollments')
                    ->join('courses', 'enrollments.course_id', '=', 'courses.id')
                    ->where('enrollments.user_id', $user->id)
                    ->select('enrollments.id', 'enrollments.status as enrollment_status', 'enrollments.created_at', 'courses.title', 'courses.slug', 'courses.price')
                    ->orderByDesc('enrollments.id')->get();
            }
            if ($student && Schema::hasTable('fee_invoices')) {
                $invoices = DB::table('fee_invoices')->where('student_id', $student->id)->latest('id')->limit(20)->get();
            }
            if ($student && Schema::hasTable('attendance_records')) {
                $attendance = DB::table('attendance_records')->where('student_id', $student->id)->latest('attendance_date')->limit(20)->get();
            }
            if (Schema::hasTable('exam_attempts') && Schema::hasTable('exams')) {
                $attempts = DB::table('exam_attempts')
                    ->join('exams', 'exam_attempts.exam_id', '=', 'exams.id')
                    ->where('exam_attempts.user_id', $user->id)
                    ->select('exam_attempts.*', 'exams.title as exam_title')
                    ->latest('exam_attempts.id')->limit(20)->get();
            }
            $metrics = [
                'active_courses' => $enrollments->where('enrollment_status', 'active')->count(),
                'pending_enrollments' => $enrollments->where('enrollment_status', 'pending')->count(),
                'unpaid_invoices' => $invoices->whereIn('status', ['unpaid', 'partial'])->count(),
                'exam_attempts' => $attempts->count(),
            ];
        } elseif ($role === 'teacher') {
            $teacher = Schema::hasTable('teachers')
                ? DB::table('teachers')->where('user_id', $user->id)->first()
                : null;
            if (Schema::hasTable('courses')) {
                $courses = DB::table('courses')->where('instructor_name', $user->name)
                    ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                    ->orderBy('title')->get();
            }
            if (Schema::hasTable('live_classes')) {
                $liveClasses = DB::table('live_classes')->where('instructor_name', $user->name)
                    ->orderBy('starts_at')->limit(20)->get();
            }
            $metrics = [
                'assigned_courses' => $courses->count(),
                'scheduled_classes' => $liveClasses->where('status', 'upcoming')->count(),
            ];
        } elseif ($role === 'guardian') {
            $guardian = Schema::hasTable('guardians')
                ? DB::table('guardians')->where('user_id', $user->id)->first()
                : null;
            $studentIds = collect();
            if ($guardian && Schema::hasTable('guardian_student') && Schema::hasTable('students') && Schema::hasTable('users')) {
                $children = DB::table('guardian_student')
                    ->join('students', 'guardian_student.student_id', '=', 'students.id')
                    ->join('users', 'students.user_id', '=', 'users.id')
                    ->where('guardian_student.guardian_id', $guardian->id)
                    ->select('students.id', 'students.student_code', 'students.status', 'users.name', 'users.email')
                    ->get();
                $studentIds = $children->pluck('id');
            }
            if ($studentIds->isNotEmpty() && Schema::hasTable('fee_invoices')) {
                $invoices = DB::table('fee_invoices')->whereIn('student_id', $studentIds)->latest('id')->limit(30)->get();
            }
            if ($studentIds->isNotEmpty() && Schema::hasTable('attendance_records')) {
                $attendance = DB::table('attendance_records')->whereIn('student_id', $studentIds)->latest('attendance_date')->limit(30)->get();
            }
            $metrics = [
                'linked_students' => $children->count(),
                'unpaid_invoices' => $invoices->whereIn('status', ['unpaid', 'partial'])->count(),
                'attendance_records' => $attendance->count(),
            ];
        } elseif ($role === 'branch_manager') {
            $metrics = [
                'students' => Schema::hasTable('students') ? DB::table('students')->where('branch_id', $branchId)->count() : 0,
                'teachers' => Schema::hasTable('teachers') ? DB::table('teachers')->where('branch_id', $branchId)->count() : 0,
                'courses' => Schema::hasTable('courses') ? DB::table('courses')->where('branch_id', $branchId)->count() : 0,
                'pending_enrollments' => Schema::hasTable('enrollments') ? DB::table('enrollments')->where('branch_id', $branchId)->where('status', 'pending')->count() : 0,
                'unpaid_invoices' => Schema::hasTable('fee_invoices') ? DB::table('fee_invoices')->where('branch_id', $branchId)->whereIn('status', ['unpaid', 'partial'])->count() : 0,
            ];
        } elseif ($role === 'accountant') {
            $invoices = Schema::hasTable('fee_invoices')
                ? DB::table('fee_invoices')->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))->latest('id')->limit(30)->get()
                : collect();
            $metrics = [
                'invoices' => $invoices->count(),
                'unpaid_invoices' => $invoices->whereIn('status', ['unpaid', 'partial'])->count(),
            ];
        }

        $notices = Schema::hasTable('notices')
            ? DB::table('notices')
                ->where(function ($q) use ($role) {
                    $q->whereNull('audience_role')->orWhere('audience_role', $role);
                })
                ->where(function ($q) use ($branchId) {
                    $q->whereNull('branch_id');
                    if ($branchId !== null) $q->orWhere('branch_id', $branchId);
                })
                ->where(function ($q) {
                    $q->whereNull('published_at')->orWhere('published_at', '<=', now());
                })
                ->orderByDesc('is_pinned')->orderByDesc('published_at')->limit(10)->get()
            : collect();

        return view('portals.dashboard', compact(
            'user', 'role', 'student', 'teacher', 'guardian', 'enrollments',
            'invoices', 'attendance', 'attempts', 'courses', 'liveClasses',
            'children', 'metrics', 'notices'
        ));
    }
}
