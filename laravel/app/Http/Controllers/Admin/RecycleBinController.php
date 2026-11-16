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
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\CourseCategory;
use Plugins\Modules\CourseManagement\Models\LiveClass;
use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\BatchManagement\Models\StudyBatch;
use Plugins\Modules\ClassManagement\Models\AcademicClass;

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
        $courses = new LengthAwarePaginator([], 0, 25);
        $courseCategories = new LengthAwarePaginator([], 0, 25);
        $liveClasses = new LengthAwarePaginator([], 0, 25);
        $exams = new LengthAwarePaginator([], 0, 25);
        $batches = new LengthAwarePaginator([], 0, 25);
        $classes = new LengthAwarePaginator([], 0, 25);
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

        if ($this->plugins->isEnabled('course-management') && Schema::hasTable('courses') && Schema::hasColumn('courses', 'deleted_at')) {
            $courses = Course::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'courses_page');
        }
        if ($this->plugins->isEnabled('course-management') && Schema::hasTable('course_categories') && Schema::hasColumn('course_categories', 'deleted_at')) {
            $courseCategories = CourseCategory::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'course_categories_page');
        }
        if ($this->plugins->isEnabled('course-management') && Schema::hasTable('live_classes') && Schema::hasColumn('live_classes', 'deleted_at')) {
            $liveClasses = LiveClass::withTrashed()->with('course')->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'live_classes_page');
        }
        if ($this->plugins->isEnabled('exam-management') && Schema::hasTable('exams') && Schema::hasColumn('exams', 'deleted_at')) {
            $exams = Exam::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'exams_page');
        }
        if ($this->plugins->isEnabled('batch-management') && Schema::hasTable('study_batches') && Schema::hasColumn('study_batches', 'deleted_at')) {
            $batches = StudyBatch::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'batches_page');
        }
        if ($this->plugins->isEnabled('class-management') && Schema::hasTable('academic_classes') && Schema::hasColumn('academic_classes', 'deleted_at')) {
            $classes = AcademicClass::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'classes_page');
        }

        return view('admin.recycle-bin.index', compact('students', 'teachers', 'courses', 'courseCategories', 'liveClasses', 'exams', 'batches', 'classes'));
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

            if ($teacher->user) {
                $teacher->user->update(['is_active' => $wasActive]);
            }

            $this->audit->log('recycle_bin.teacher_restored', 'teacher', (string) $teacher->id, [
                'employee_code' => $teacher->employee_code,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'Instructor restored successfully.');
    }

    public function restoreCourse(Request $request, int $recordId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless($this->plugins->isEnabled('course-management') && Schema::hasTable('courses') && Schema::hasColumn('courses', 'deleted_at'), 404);

        DB::transaction(function () use ($recordId, $request): void {
            $record = Course::withTrashed()->lockForUpdate()->findOrFail($recordId);
            abort_unless($record->trashed(), 409, 'This record is not in the Recycle Bin.');
            $record->restore();
            $this->audit->log('recycle_bin.course_restored', 'course', (string) $record->id, [
                'title' => $record->title,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'Course restored successfully.');
    }

    public function restoreExam(Request $request, int $recordId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless($this->plugins->isEnabled('exam-management') && Schema::hasTable('exams') && Schema::hasColumn('exams', 'deleted_at'), 404);

        DB::transaction(function () use ($recordId, $request): void {
            $record = Exam::withTrashed()->lockForUpdate()->findOrFail($recordId);
            abort_unless($record->trashed(), 409, 'This record is not in the Recycle Bin.');
            $record->restore();
            $this->audit->log('recycle_bin.exam_restored', 'exam', (string) $record->id, [
                'title' => $record->title,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'Exam restored successfully.');
    }

    public function restoreBatch(Request $request, int $recordId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless($this->plugins->isEnabled('batch-management') && Schema::hasTable('study_batches') && Schema::hasColumn('study_batches', 'deleted_at'), 404);

        DB::transaction(function () use ($recordId, $request): void {
            $record = StudyBatch::withTrashed()->lockForUpdate()->findOrFail($recordId);
            abort_unless($record->trashed(), 409, 'This record is not in the Recycle Bin.');
            $record->restore();
            $this->audit->log('recycle_bin.batch_restored', 'batch', (string) $record->id, [
                'name' => $record->name,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'StudyBatch restored successfully.');
    }

    public function restoreClass(Request $request, int $recordId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless($this->plugins->isEnabled('class-management') && Schema::hasTable('academic_classes') && Schema::hasColumn('academic_classes', 'deleted_at'), 404);

        DB::transaction(function () use ($recordId, $request): void {
            $record = AcademicClass::withTrashed()->lockForUpdate()->findOrFail($recordId);
            abort_unless($record->trashed(), 409, 'This record is not in the Recycle Bin.');
            $record->restore();
            $this->audit->log('recycle_bin.class_restored', 'class', (string) $record->id, [
                'name' => $record->name,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'AcademicClass restored successfully.');
    }

    public function restoreCourseCategory(Request $request, int $recordId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless($this->plugins->isEnabled('course-management') && Schema::hasTable('course_categories') && Schema::hasColumn('course_categories', 'deleted_at'), 404);

        DB::transaction(function () use ($recordId, $request): void {
            $record = CourseCategory::withTrashed()->lockForUpdate()->findOrFail($recordId);
            abort_unless($record->trashed(), 409, 'This category is not in the Recycle Bin.');
            $record->restore();
            $this->audit->log('recycle_bin.course_category_restored', 'course_category', (string) $record->id, [
                'name' => $record->name,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'Course category restored successfully.');
    }

    public function restoreLiveClass(Request $request, int $recordId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless($this->plugins->isEnabled('course-management') && Schema::hasTable('live_classes') && Schema::hasColumn('live_classes', 'deleted_at'), 404);

        DB::transaction(function () use ($recordId, $request): void {
            $record = LiveClass::withTrashed()->lockForUpdate()->findOrFail($recordId);
            abort_unless($record->trashed(), 409, 'This live class is not in the Recycle Bin.');
            $record->restore();
            $this->audit->log('recycle_bin.live_class_restored', 'live_class', (string) $record->id, [
                'title' => $record->title,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'Live class restored successfully.');
    }

}
