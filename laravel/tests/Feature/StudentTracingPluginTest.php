<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StudentTracingPluginTest extends TestCase
{
    public function test_student_tracing_installs_without_student_management_and_records_student_lifecycle_events(): void
    {
        $this->markSystemInstalled();

        $manager = app(PluginManager::class);
        $manager->syncCatalog();

        $manifest = $manager->discoverManifests()['student-tracing'] ?? null;
        $this->assertNotNull($manifest);
        $this->assertSame([], $manifest->dependencies);

        $manager->install('student-tracing');
        $this->assertTrue($manager->isEnabled('student-tracing'));
        $this->assertFalse($manager->isEnabled('student-management'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('student_activity_events'));

        $student = (object) ['id' => 901, 'branch_id' => 12];
        Event::dispatch(
            'eloquent.created: Plugins\Modules\StudentManagement\Models\Student',
            [$student]
        );

        $this->assertDatabaseHas('student_activity_events', [
            'student_id' => 901,
            'branch_id' => 12,
            'event_type' => 'student.created',
        ]);

        Schema::create('students', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('branch_id')->nullable();
        });
        DB::table('students')->insert(['id' => 903, 'user_id' => 904, 'branch_id' => 14]);

        Event::dispatch(
            'eloquent.created: Plugins\\Modules\\AttendanceManagement\\Models\\AttendanceRecord',
            [(object) ['id' => 905, 'student_id' => 903, 'branch_id' => 14]]
        );
        Event::dispatch(
            'eloquent.created: Plugins\\Modules\\CourseManagement\\Models\\Enrollment',
            [(object) ['id' => 906, 'user_id' => 904]]
        );
        Event::dispatch(
            'eloquent.created: Plugins\\Modules\\ExamManagement\\Models\\ExamAttempt',
            [(object) ['id' => 907, 'user_id' => 904]]
        );

        $this->assertDatabaseHas('student_activity_events', ['student_id' => 903, 'event_type' => 'attendance.created']);
        $this->assertDatabaseHas('student_activity_events', ['student_id' => 903, 'event_type' => 'enrollment.created']);
        $this->assertDatabaseHas('student_activity_events', ['student_id' => 903, 'event_type' => 'exam_attempt.created']);
    }

    public function test_student_trace_uninstall_preserves_history(): void
    {
        $this->markSystemInstalled();
        $manager = app(PluginManager::class);
        $manager->syncCatalog();
        $manager->install('student-tracing');

        DB::table('student_activity_events')->insert([
            'student_id' => 902,
            'branch_id' => 12,
            'actor_id' => null,
            'event_type' => 'student.created',
            'metadata' => json_encode(['changed_fields' => []]),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager->disable('student-tracing');
        $manager->uninstall('student-tracing', false);

        $this->assertDatabaseHas('student_activity_events', [
            'student_id' => 902,
            'event_type' => 'student.created',
        ]);
    }
}
