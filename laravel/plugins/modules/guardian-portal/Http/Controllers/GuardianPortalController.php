<?php

declare(strict_types=1);

namespace Plugins\Modules\GuardianPortal\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class GuardianPortalController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('guardian'), 403, 'Guardian accounts only.');

        $plugins = app(PluginManager::class);
        $guardianIntegration = $plugins->isEnabled('guardian-management')
            && $plugins->isEnabled('student-management')
            && Schema::hasTable('guardians')
            && Schema::hasTable('guardian_student')
            && Schema::hasTable('students');

        $guardian = $guardianIntegration
            ? DB::table('guardians')->where('user_id', $actor->id)->first()
            : null;

        if ($guardianIntegration) {
            abort_unless($guardian, 404, 'Guardian profile not found.');
        }

        $children = collect();
        if ($guardian) {
            $children = DB::table('guardian_student as gs')
                ->join('students as s', 's.id', '=', 'gs.student_id')
                ->join('users as u', 'u.id', '=', 's.user_id')
                ->where('gs.guardian_id', $guardian->id)
                ->when($guardian->branch_id !== null, fn ($query) => $query->where(function ($branch) use ($guardian): void {
                    $branch->whereNull('s.branch_id')->orWhere('s.branch_id', $guardian->branch_id);
                }))
                ->select([
                    's.id as student_id',
                    's.user_id',
                    's.branch_id',
                    's.student_code',
                    's.status as student_status',
                    'u.name as student_name',
                ])
                ->orderBy('u.name')
                ->get();
        }

        $selectedId = $request->query('student');
        $selected = null;
        if ($selectedId !== null && $selectedId !== '') {
            abort_unless(is_scalar($selectedId) && ctype_digit((string) $selectedId), 404);
            $selected = $children->firstWhere('student_id', (int) $selectedId);
            abort_unless($selected, 404, 'This student is not linked to your guardian account.');
        } elseif ($children->isNotEmpty()) {
            $selected = $children->first();
        }

        $summary = [
            'courses' => 0,
            'activity_count' => 0,
            'attendance_total' => 0,
            'attendance_present' => 0,
            'attendance_absent' => 0,
            'attendance_late' => 0,
            'exam_attempts' => 0,
            'average_score' => null,
            'unpaid_amount' => 0.0,
        ];
        $courses = collect();
        $activities = collect();
        $attendance = collect();
        $examResults = collect();
        $invoices = collect();
        $notices = collect();

        if ($selected) {
            if ($plugins->isEnabled('course-management') && Schema::hasTable('enrollments') && Schema::hasTable('courses')) {
                $courses = DB::table('enrollments')
                    ->join('courses', 'courses.id', '=', 'enrollments.course_id')
                    ->where('enrollments.user_id', $selected->user_id)
                    ->whereIn('enrollments.status', ['active', 'completed'])
                    ->when($selected->branch_id !== null, fn ($query) => $query->where(function ($branch) use ($selected): void {
                        $branch->whereNull('enrollments.branch_id')->orWhere('enrollments.branch_id', $selected->branch_id);
                    }))
                    ->select('courses.id', 'courses.title', 'courses.slug', 'enrollments.status as enrollment_status')
                    ->orderBy('courses.title')
                    ->get();
                $summary['courses'] = $courses->count();
            }

            if ($plugins->isEnabled('student-tracking') && Schema::hasTable('student_activity_events')) {
                $activityQuery = DB::table('student_activity_events')->where('user_id', $selected->user_id);
                $summary['activity_count'] = (clone $activityQuery)->where('occurred_at', '>=', now()->subDays(30))->count();
                $activities = $activityQuery->latest('occurred_at')->limit(10)->get();
            }

            if ($plugins->isEnabled('attendance-management')) {
                $attendanceTable = Schema::hasTable('attendance_entries') ? 'attendance_entries'
                    : (Schema::hasTable('attendance_records') ? 'attendance_records' : null);
                if ($attendanceTable !== null) {
                    $attendanceQuery = DB::table($attendanceTable)->where('student_id', $selected->student_id);
                    $summary['attendance_total'] = (clone $attendanceQuery)->count();
                    $summary['attendance_present'] = (clone $attendanceQuery)->where('status', 'present')->count();
                    $summary['attendance_absent'] = (clone $attendanceQuery)->where('status', 'absent')->count();
                    $summary['attendance_late'] = (clone $attendanceQuery)->where('status', 'late')->count();
                    $attendance = $attendanceQuery->latest('attendance_date')->limit(20)->get();
                }
            }

            if ($plugins->isEnabled('exam-management') && Schema::hasTable('exam_attempts') && Schema::hasTable('exams')) {
                $examResults = DB::table('exam_attempts')
                    ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
                    ->where('exam_attempts.user_id', $selected->user_id)
                    ->select('exam_attempts.id', 'exam_attempts.score_obtained', 'exam_attempts.total_marks', 'exam_attempts.passed', 'exam_attempts.submitted_at', 'exams.title as exam_title')
                    ->latest('exam_attempts.submitted_at')
                    ->limit(10)
                    ->get();
                $summary['exam_attempts'] = $examResults->count();
                $scored = $examResults->filter(fn ($attempt) => (float) $attempt->total_marks > 0);
                $summary['average_score'] = $scored->isNotEmpty()
                    ? round($scored->avg(fn ($attempt) => ((float) $attempt->score_obtained / (float) $attempt->total_marks) * 100), 1)
                    : null;
            }

            if ($plugins->isEnabled('fees-management') && Schema::hasTable('fee_invoices')) {
                $invoiceQuery = DB::table('fee_invoices')->where('student_id', $selected->student_id);
                $summary['unpaid_amount'] = (float) (clone $invoiceQuery)
                    ->selectRaw('COALESCE(SUM(CASE WHEN net_amount > paid_amount THEN net_amount - paid_amount ELSE 0 END), 0) as outstanding')
                    ->value('outstanding');
                $invoices = $invoiceQuery->latest('created_at')->limit(10)->get();
            }

            if ($plugins->isEnabled('notice-board') && Schema::hasTable('notices')) {
                $notices = DB::table('notices')
                    ->whereIn('audience_role', ['all', 'student', 'guardian'])
                    ->whereNotNull('published_at')
                    ->where('published_at', '<=', now())
                    ->where(function ($query) use ($selected): void {
                        $query->whereNull('branch_id');
                        if ($selected->branch_id !== null) {
                            $query->orWhere('branch_id', $selected->branch_id);
                        }
                    })
                    ->latest('published_at')
                    ->limit(10)
                    ->get(['id', 'title', 'body', 'audience_role', 'published_at']);
            }
        }

        return view('guardian-portal::dashboard', [
            'guardian' => $guardian,
            'children' => $children,
            'selected' => $selected,
            'integrations' => [
                'guardian' => $guardianIntegration,
                'courses' => $plugins->isEnabled('course-management'),
                'tracking' => $plugins->isEnabled('student-tracking'),
                'attendance' => $plugins->isEnabled('attendance-management'),
                'exams' => $plugins->isEnabled('exam-management'),
                'fees' => $plugins->isEnabled('fees-management'),
                'notices' => $plugins->isEnabled('notice-board'),
            ],
            'summary' => $summary,
            'courses' => $courses,
            'activities' => $activities,
            'attendance' => $attendance,
            'examResults' => $examResults,
            'invoices' => $invoices,
            'notices' => $notices,
        ]);
    }
}
