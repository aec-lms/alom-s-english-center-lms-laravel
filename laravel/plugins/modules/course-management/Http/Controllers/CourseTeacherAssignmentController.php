<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Core\Security\BranchAccess;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CourseTeacherAssignmentController extends Controller
{
    public function __construct(private readonly BranchAccess $branches) {}
    public function index(Request $request): View
    {
        $branchId = $this->branches->assignedBranchId($request->user());
        $courses = DB::table('courses')->whereNull('deleted_at')->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))->orderBy('title')->get(['id', 'title', 'branch_id']);
        $teachers = User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('slug', 'teacher'))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')->get(['id', 'name', 'email', 'branch_id']);
        $assignments = DB::table('course_teacher_assignments')
            ->join('courses', 'courses.id', '=', 'course_teacher_assignments.course_id')
            ->join('users', 'users.id', '=', 'course_teacher_assignments.teacher_user_id')
            ->when($branchId !== null, fn ($q) => $q->where('courses.branch_id', $branchId)->where('users.branch_id', $branchId))
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
        $branchId = $this->branches->assignedBranchId($request->user());
        $course = DB::table('courses')->where('id', $data['course_id'])->whereNull('deleted_at')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->first();
        abort_unless($course, 404, 'Course not found in your assigned branch.');
        $teacher = User::query()->findOrFail($data['teacher_user_id']);
        abort_unless($teacher->hasRole('teacher') && (bool) $teacher->is_active, 422, 'Select an active teacher account.');
        if ($branchId !== null) {
            abort_unless((int) $course->branch_id === $branchId && (int) $teacher->branch_id === $branchId, 404, 'Course and teacher must belong to your assigned branch.');
        }
        abort_if($course->branch_id !== null && (int) $teacher->branch_id !== (int) $course->branch_id, 422, 'Teacher and course must belong to the same branch.');

        DB::table('course_teacher_assignments')->updateOrInsert(
            ['course_id' => $course->id, 'teacher_user_id' => $teacher->id],
            ['assigned_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]
        );

        return back()->with('status', 'Teacher assigned to course.');
    }

    public function destroy(Request $request, int $assignment): RedirectResponse
    {
        $record = DB::table('course_teacher_assignments')
            ->join('courses', 'courses.id', '=', 'course_teacher_assignments.course_id')
            ->where('course_teacher_assignments.id', $assignment)
            ->select('course_teacher_assignments.id', 'courses.branch_id')
            ->first();
        abort_unless($record, 404);
        $this->branches->assertRecord($request->user(), $record);
        DB::table('course_teacher_assignments')->where('id', $assignment)->delete();
        return back()->with('status', 'Course teacher assignment removed.');
    }
}
