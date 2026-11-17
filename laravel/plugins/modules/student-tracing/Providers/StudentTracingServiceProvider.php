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

        // Listen by model-event name so this plugin can install without requiring
        // the Student Management plugin or importing its PHP classes.
        foreach ([
            'created' => 'student.created',
            'updated' => 'student.updated',
            'deleted' => 'student.deleted',
        ] as $eloquentEvent => $traceEvent) {
            Event::listen(
                'eloquent.' . $eloquentEvent . ': Plugins\Modules\StudentManagement\Models\Student',
                function (object $student) use ($traceEvent): void {
                    if (!Schema::hasTable('student_activity_events') || !isset($student->id)) {
                        return;
                    }

                    $changed = [];
                    if ($eloquentEvent === 'updated' && method_exists($student, 'getChanges')) {
                        $changed = array_values(array_intersect(
                            array_keys($student->getChanges()),
                            ['status', 'branch_id']
                        ));
                    }

                    try {
                        DB::table('student_activity_events')->insert([
                            'student_id' => (int) $student->id,
                            'branch_id' => isset($student->branch_id) ? (int) $student->branch_id : null,
                            'actor_id' => Auth::id(),
                            'event_type' => $traceEvent,
                            'metadata' => json_encode(['changed_fields' => $changed], JSON_THROW_ON_ERROR),
                            'occurred_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } catch (Throwable) {
                        // Tracking must never interrupt a student-management workflow.
                        report(new \RuntimeException('Student tracing event could not be persisted.'));
                    }
                }
            );
        }
    }
}
