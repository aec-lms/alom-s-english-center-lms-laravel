<?php

declare(strict_types=1);

namespace Plugins\Modules\StudentTracing\Providers;

use App\Core\Plugins\BasePluginServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Throwable;

class StudentTracingServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'student-tracing';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }

    public function boot(): void
    {
        parent::boot();

        // Event names keep this plugin installable without importing sibling modules.
        $sources = [
            'Plugins\Modules\StudentManagement\Models\Student' => ['student', 'id', 'branch_id'],
            'Plugins\Modules\AttendanceManagement\Models\AttendanceRecord' => ['attendance', 'student_id', 'branch_id'],
            'Plugins\Modules\CourseManagement\Models\Enrollment' => ['enrollment', 'user_id', null],
            'Plugins\Modules\ExamManagement\Models\ExamAttempt' => ['exam_attempt', 'user_id', null],
        ];

        foreach ($sources as $modelClass => [$source, $studentKey, $branchKey]) {
            foreach (['created', 'updated', 'deleted'] as $action) {
                Event::listen(
                    'eloquent.' . $action . ': ' . $modelClass,
                    function (object $model) use ($source, $studentKey, $branchKey, $action): void {
                        $studentId = $this->resolveStudentId($model, $studentKey, $source);
                        if (!$studentId || !Schema::hasTable('student_activity_events')) {
                            return;
                        }

                        $branchId = $branchKey && isset($model->{$branchKey})
                            ? (int) $model->{$branchKey}
                            : null;
                        if ($branchId === null && Schema::hasTable('students')) {
                            $branchId = DB::table('students')->where('id', $studentId)->value('branch_id');
                        }

                        $allowedFields = ['status', 'branch_id', 'score_obtained', 'passed'];
                        $changed = $action === 'updated' && method_exists($model, 'getChanges')
                            ? array_values(array_intersect(array_keys($model->getChanges()), $allowedFields))
                            : [];

                        try {
                            DB::table('student_activity_events')->insert([
                                'student_id' => $studentId,
                                'branch_id' => $branchId,
                                'actor_id' => Auth::id(),
                                'event_type' => $source . '.' . $action,
                                'metadata' => json_encode(['changed_fields' => $changed], JSON_THROW_ON_ERROR),
                                'occurred_at' => now(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        } catch (Throwable) {
                            // Tracing must never interrupt an academic workflow.
                            report(new \RuntimeException('Student tracing event could not be persisted.'));
                        }
                    }
                );
            }
        }
    }

    private function resolveStudentId(object $model, string $studentKey, string $source): ?int
    {
        if ($source === 'student') {
            return isset($model->id) ? (int) $model->id : null;
        }

        if ($studentKey === 'student_id') {
            return isset($model->student_id) ? (int) $model->student_id : null;
        }

        if (!isset($model->user_id) || !Schema::hasTable('students')) {
            return null;
        }

        $studentId = DB::table('students')->where('user_id', (int) $model->user_id)->value('id');

        return $studentId !== null ? (int) $studentId : null;
    }
}
