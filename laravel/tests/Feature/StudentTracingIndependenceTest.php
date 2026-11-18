<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentTracingIndependenceTest extends TestCase
{
    public function test_student_tracing_installs_with_private_storage_without_student_tracking(): void
    {
        $this->markSystemInstalled();

        $manager = app(PluginManager::class);
        $manager->syncCatalog();
        $manager->install('student-tracing');

        $this->assertTrue(Schema::hasTable('student_trace_events'));
        $this->assertFalse(Schema::hasTable('student_activity_events'));
    }

    public function test_student_tracing_then_student_tracking_installation_keeps_both_stores_independent(): void
    {
        $this->markSystemInstalled();

        $manager = app(PluginManager::class);
        $manager->syncCatalog();
        $manager->install('student-tracing');
        $manager->install('student-tracking');

        $this->assertTrue(Schema::hasTable('student_trace_events'));
        $this->assertTrue(Schema::hasTable('student_activity_events'));
        $this->assertTrue(Schema::hasColumn('student_trace_events', 'student_id'));
        $this->assertTrue(Schema::hasColumn('student_trace_events', 'branch_id'));
    }

    public function test_student_tracking_then_student_tracing_installation_keeps_both_stores_independent(): void
    {
        $this->markSystemInstalled();

        $manager = app(PluginManager::class);
        $manager->syncCatalog();
        $manager->install('student-tracking');

        DB::table('student_activity_events')->insert([
            'user_id' => 123,
            'actor_id' => 123,
            'event_type' => 'lesson_completed',
            'subject_type' => 'lesson',
            'subject_id' => 'lesson-55',
            'duration_seconds' => 120,
            'metadata' => json_encode(['source' => 'legacy-test']),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager->install('student-tracing');

        $this->assertDatabaseHas('student_trace_events', [
            'legacy_event_id' => 1,
            'event_type' => 'lesson_completed',
        ]);
        $this->assertTrue(Schema::hasTable('student_trace_events'));
        $this->assertTrue(Schema::hasTable('student_activity_events'));
        $this->assertTrue(Schema::hasColumn('student_trace_events', 'student_id'));
        $this->assertTrue(Schema::hasColumn('student_trace_events', 'branch_id'));
    }
}
