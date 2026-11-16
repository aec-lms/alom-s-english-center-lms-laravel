<?php

declare(strict_types=1);

namespace Tests\\Feature;

use App\\Core\\Plugins\\PluginManager;
use Plugins\\Modules\\StudentTracking\\Models\\StudentActivityEvent;
use Tests\\TestCase;

class StudentTrackingPluginTest extends TestCase
{
    public function test_student_tracking_installs_without_academic_sibling_plugins_and_records_safe_events(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-tracking']);
        $admin = $this->createUserWithRole('admin');

        $manager = app(PluginManager::class);
        $this->assertTrue($manager->isInstalled('student-tracking'));
        $this->assertTrue($manager->isEnabled('student-tracking'));
        $this->assertFalse($manager->isInstalled('student-management'));
        $this->assertFalse($manager->isInstalled('attendance-management'));
        $this->assertFalse($manager->isInstalled('course-management'));
        $this->assertDatabaseHas('student_activity_events', []);

        $this->actingAs($admin)->postJson('/student-tracking/events', [
            'event_type' => 'lesson_completed',
            'subject_type' => 'lesson',
            'subject_id' => 'lesson-42',
            'duration_seconds' => 420,
            'metadata' => [
                'course_title' => 'SSC English Grammar',
                'result_percent' => 92,
                'password' => 'must-not-be-stored',
                'precise_location' => 'must-not-be-stored',
            ],
        ])->assertCreated()->assertJsonPath('recorded', true);

        $event = StudentActivityEvent::query()->firstOrFail();
        $this->assertSame('lesson_completed', $event->event_type);
        $this->assertSame('SSC English Grammar', $event->metadata['course_title']);
        $this->assertSame('92', $event->metadata['result_percent']);
        $this->assertArrayNotHasKey('password', $event->metadata);
        $this->assertArrayNotHasKey('precise_location', $event->metadata);

        $this->actingAs($admin)->get('/admin/student-tracking')
            ->assertOk()
            ->assertSee('Student Tracking & Analytics')
            ->assertSee('SSC English Grammar');

        $this->actingAs($admin)->get('/student-tracking/mine')
            ->assertOk()
            ->assertSee('My Learning Activity')
            ->assertSee('SSC English Grammar');
    }

    public function test_student_tracking_rejects_unknown_event_types(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-tracking']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->postJson('/student-tracking/events', [
            'event_type' => 'screen_recording',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('student_activity_events', 0);
    }
}
