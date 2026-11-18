<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CourseTeacherAssignmentController extends Controller
{
    public function index(): View
    {
        $courses = DB::table('courses')->whereNull('deleted_at')->orderBy('title')->get(['id', 'title', 'branch_id']);
        $teachers = User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'teacher'))
            ->orderBy('name')->get(['id', 'name', 'email', 'branch_id']);
        $assignments = DB::table('course_teacher_assignments')
            ->join('courses', 'courses.id', '=', 'course_teacher_assignments.course_id')
            ->join('users', 'users.id', '=', 'course_teacher_assignments.teacher_user_id')
            ->select('course_teacher_assignments.*', 'courses.title as course_title', 'users.name as teacher_name', 'users.email as teacher_email')
            ->orderBy('courses.title')->paginate(30);

        return view('course-management::teacher-assignments', compact('courses', 'teachers', 'assignments'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'teacher_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $course = DB::table('courses')->where('id', $data['course_id'])->whereNull('deleted_at')->first();
        $teacher = User::query()->findOrFail($data['teacher_user_id']);
        abort_unless($teacher->hasRole('teacher'), 422, 'Select an active teacher account.');
        abort_if($course->branch_id !== null && (int) $teacher->branch_id !== (int) $course->branch_id, 422, 'Teacher and course must belong to the same branch.');

        DB::table('course_teacher_assignments')->updateOrInsert(
            ['course_id' => $course->id, 'teacher_user_id' => $teacher->id],
            ['assigned_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]
        );

        return back()->with('status', 'Teacher assigned to course.');
    }

    public function destroy(Request $request, int $assignment): RedirectResponse
    {
        DB::table('course_teacher_assignments')->where('id', $assignment)->delete();
        return back()->with('status', 'Course teacher assignment removed.');
    }
}
