<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Core\Plugins\PluginManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\StudentManagement\Models\Student;

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
        if ($this->plugins->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'deleted_at')) {
            $students = Student::withTrashed()
                ->with('user')
                ->whereNotNull('deleted_at')
                ->latest('deleted_at')
                ->paginate(25);
        }

        return view('admin.recycle-bin.index', compact('students'));
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
}
