<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Core\Plugins\PluginManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\StudentManagement\Models\Student;
use Plugins\Modules\TeacherManagement\Models\Teacher;

class RecycleBinController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PluginManager $plugins,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasRole('admin'), 403);

        $students = new LengthAwarePaginator([], 0, 25);
        $teachers = new LengthAwarePaginator([], 0, 25);
        if ($this->plugins->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'deleted_at')) {
            $students = Student::withTrashed()
                ->with('user')
                ->whereNotNull('deleted_at')
                ->latest('deleted_at')
                ->paginate(25);
        }

        if ($this->plugins->isEnabled('teacher-management')
            && Schema::hasTable('teachers')
            && Schema::hasColumn('teachers', 'deleted_at')) {
            $teachers = Teacher::withTrashed()
                ->with('user')
                ->whereNotNull('deleted_at')
                ->latest('deleted_at')
                ->paginate(25, ['*'], 'teachers_page');
        }

        return view('admin.recycle-bin.index', compact('students', 'teachers'));
    }

    public function restoreStudent(Request $request, int $studentId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless(
            $this->plugins->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'deleted_at'),
            404
        );

        DB::transaction(function () use ($studentId, $request): void {
            $student = Student::withTrashed()->lockForUpdate()->findOrFail($studentId);
            abort_unless($student->trashed(), 409, 'This student is not in the Recycle Bin.');

            $wasActive = (bool) $student->deleted_user_was_active;
            $student->restore();
            $student->deleted_user_was_active = null;
            $student->save();

            if ($student->user) {
                $student->user->update(['is_active' => $wasActive]);
            }

            $this->audit->log('recycle_bin.student_restored', 'student', (string) $student->id, [
                'student_code' => $student->student_code,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'Student restored successfully. Account access was returned to its previous active state.');
    }
    public function restoreTeacher(Request $request, int $teacherId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless(
            $this->plugins->isEnabled('teacher-management')
            && Schema::hasTable('teachers')
            && Schema::hasColumn('teachers', 'deleted_at'),
            404
        );

        DB::transaction(function () use ($teacherId, $request): void {
            $teacher = Teacher::withTrashed()->lockForUpdate()->findOrFail($teacherId);
            abort_unless($teacher->trashed(), 409, 'This instructor is not in the Recycle Bin.');

            $wasActive = (bool) $teacher->deleted_user_was_active;
            $teacher->restore();
            $teacher->deleted_user_was_active = null;
            $teacher->save();
            $teacher->update(['is_active' => $wasActive]);

            if ($teacher->user) {
                $teacher->user->update(['is_active' => $wasActive]);
            }

            $this->audit->log('recycle_bin.teacher_restored', 'teacher', (string) $teacher->id, [
                'employee_code' => $teacher->employee_code,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'Instructor restored successfully.');
    }

}
