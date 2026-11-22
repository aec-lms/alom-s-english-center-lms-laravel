<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Plugins\PluginManager;
use Illuminate\Http\Request;
use Plugins\Modules\RoleAiAssistant\Services\AiAssistantService;
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
        $boardQuestionSolverAvailable = false;
        if ($role === 'student') {
            $pluginManager = app(PluginManager::class);
            if ($pluginManager->isEnabled('board-question-solver')
                && $pluginManager->isEnabled('role-ai-assistant')
                && filter_var($pluginManager->getSetting('board-question-solver', 'enabled', '0'), FILTER_VALIDATE_BOOLEAN)
                && $user->hasPermission('board-solver.use')) {
                $assistant = app(AiAssistantService::class);
                $boardQuestionSolverAvailable = $assistant->isRoleAllowed($user) && $assistant->isProviderConfigured();
            }
        }
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
                    ->select('enrollments.id', 'enrollments.status as enrollment_status', 'enrollments.created_at', 'courses.id as course_id', 'courses.title', 'courses.slug', 'courses.price')
                    ->orderByDesc('enrollments.id')->get();

                $trackableCourseIds = $enrollments
                    ->whereIn('enrollment_status', ['active', 'completed'])
                    ->pluck('course_id')
                    ->filter()
                    ->unique()
                    ->values();

                $progressByCourse = collect();
                $hasProgressSchema = Schema::hasTable('course_curriculum_items')
                    && Schema::hasTable('course_content_posts')
                    && Schema::hasTable('course_content_progress')
                    && Schema::hasColumn('course_curriculum_items', 'is_active')
                    && Schema::hasColumn('course_curriculum_items', 'is_preview')
                    && Schema::hasColumn('course_content_posts', 'published_at')
                    && Schema::hasColumn('course_content_progress', 'completed_at');

                if ($trackableCourseIds->isNotEmpty() && $hasProgressSchema) {
                    $progressByCourse = DB::table('course_curriculum_items as curriculum')
                        ->join('course_content_posts as posts', 'posts.id', '=', 'curriculum.course_content_post_id')
                        ->leftJoin('course_content_progress as progress', function ($join) use ($user): void {
                            $join->on('progress.course_curriculum_item_id', '=', 'curriculum.id')
                                ->where('progress.user_id', '=', $user->id)
                                ->whereNotNull('progress.completed_at');
                        })
                        ->whereIn('curriculum.course_id', $trackableCourseIds)
                        ->where('curriculum.is_active', true)
                        ->where('curriculum.is_preview', false)
                        ->where('posts.status', 'published')
                        ->whereNotNull('posts.published_at')
                        ->where('posts.published_at', '<=', now())
                        ->groupBy('curriculum.course_id')
                        ->select('curriculum.course_id')
                        ->selectRaw('COUNT(curriculum.id) as total_lessons, COUNT(progress.id) as completed_lessons')
                        ->get()
                        ->keyBy('course_id');
                }

                $enrollments->each(function ($enrollment) use ($progressByCourse): void {
                    $progress = $progressByCourse->get($enrollment->course_id);
                    $enrollment->total_lessons = (int) ($progress->total_lessons ?? 0);
                    $enrollment->completed_lessons = (int) ($progress->completed_lessons ?? 0);
                    $enrollment->progress_percent = $enrollment->total_lessons > 0
                        ? min(100, (int) round(($enrollment->completed_lessons / $enrollment->total_lessons) * 100))
                        : 0;
                });
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
                $hasTeacherAssignmentTable = Schema::hasTable('course_teacher_assignments');
                $assignedCourseIds = $hasTeacherAssignmentTable
                    ? DB::table('course_teacher_assignments')
                        ->where('teacher_user_id', $user->id)
                        ->pluck('course_id')
                    : collect();

                $courses = DB::table('courses')
                    ->where(function ($query) use ($user, $assignedCourseIds, $hasTeacherAssignmentTable): void {
                        if ($hasTeacherAssignmentTable) {
                            $query->whereIn('id', $assignedCourseIds)
                                ->orWhere(function ($legacy) use ($user): void {
                                    // Keep legacy name-based assignments only when the course
                                    // has no normalized teacher assignment.
                                    $legacy->where('instructor_name', $user->name)
                                        ->whereNotExists(function ($assignment): void {
                                            $assignment->selectRaw('1')
                                                ->from('course_teacher_assignments')
                                                ->whereColumn('course_teacher_assignments.course_id', 'courses.id');
                                        });
                                });
                        } else {
                            $query->where('instructor_name', $user->name);
                        }
                    })
                    ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                    ->orderBy('title')->get();
            }
            if (Schema::hasTable('live_classes')) {
                $liveClasses = DB::table('live_classes')
                    ->join('courses', 'live_classes.course_id', '=', 'courses.id')
                    ->where('live_classes.instructor_name', $user->name)
                    ->when($branchId !== null, fn ($q) => $q->where('courses.branch_id', $branchId))
                    ->select('live_classes.*')
                    ->orderBy('live_classes.starts_at')->limit(20)->get();
            }
            $activeStudents = $courses->isNotEmpty() && Schema::hasTable('enrollments')
                ? (int) DB::table('enrollments')
                    ->whereIn('course_id', $courses->pluck('id'))
                    ->where('status', 'active')
                    ->distinct()
                    ->count('user_id')
                : 0;
            $metrics = [
                'assigned_courses' => $courses->count(),
                'active_students' => $activeStudents,
                'scheduled_classes' => $liveClasses->where('status', 'upcoming')->count(),
            ];
            if (app(PluginManager::class)->isEnabled('assignment-management')
                && Schema::hasTable('assignment_tasks')
                && Schema::hasTable('assignment_submissions')) {
                $metrics['created_assignments'] = DB::table('assignment_tasks')
                    ->where('created_by', $user->id)
                    ->count();
                $metrics['pending_submissions'] = DB::table('assignment_submissions')
                    ->join('assignment_tasks', 'assignment_tasks.id', '=', 'assignment_submissions.assignment_id')
                    ->where('assignment_tasks.created_by', $user->id)
                    ->where('assignment_submissions.status', 'submitted')
                    ->count();
            }
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
                    ->when($guardian->branch_id !== null, fn ($query) => $query->where(function ($branch) use ($guardian): void {
                        $branch->whereNull('students.branch_id')
                            ->orWhere('students.branch_id', $guardian->branch_id);
                    }))
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
            $branchInvoices = Schema::hasTable('fee_invoices')
                ? DB::table('fee_invoices')->where('branch_id', $branchId)
                : null;
            $branchAttendance = Schema::hasTable('attendance_records')
                && Schema::hasColumn('attendance_records', 'branch_id')
                && Schema::hasColumn('attendance_records', 'attendance_date')
                ? DB::table('attendance_records')->where('branch_id', $branchId)
                : null;

            $metrics = [
                'students' => Schema::hasTable('students') ? DB::table('students')->where('branch_id', $branchId)->count() : 0,
                'teachers' => Schema::hasTable('teachers') ? DB::table('teachers')->where('branch_id', $branchId)->count() : 0,
                'courses' => Schema::hasTable('courses') ? DB::table('courses')->where('branch_id', $branchId)->count() : 0,
                'pending_enrollments' => Schema::hasTable('enrollments') ? DB::table('enrollments')->where('branch_id', $branchId)->where('status', 'pending')->count() : 0,
                'unpaid_invoices' => $branchInvoices ? (clone $branchInvoices)->whereIn('status', ['unpaid', 'partial'])->count() : 0,
                'collected_revenue' => $branchInvoices && Schema::hasColumn('fee_invoices', 'paid_amount')
                    ? (float) (clone $branchInvoices)->whereIn('status', ['paid', 'partial'])->sum('paid_amount')
                    : 0,
                'attendance_today' => $branchAttendance
                    ? (clone $branchAttendance)->whereDate('attendance_date', now()->toDateString())->count()
                    : 0,
                'present_today' => $branchAttendance && Schema::hasColumn('attendance_records', 'status')
                    ? (clone $branchAttendance)->whereDate('attendance_date', now()->toDateString())->whereIn('status', ['present', 'late'])->count()
                    : 0,
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

        $notifications = Schema::hasTable('lms_notifications')
            ? DB::table('lms_notifications')
                ->where('user_id', $user->id)
                ->latest('id')
                ->limit(10)
                ->get()
            : collect();

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
            'children', 'metrics', 'notices', 'notifications', 'boardQuestionSolverAvailable'
        ));
    }
}
