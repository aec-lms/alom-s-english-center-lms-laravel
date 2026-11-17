<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\Enrollment;
use Plugins\Modules\CourseManagement\Models\LiveClass;
use Plugins\Modules\CourseManagement\Models\LiveClassParticipant;
use Tests\TestCase;

class LiveClassCapacityTest extends TestCase
{
    public function test_only_enrolled_students_can_join_and_capacity_is_enforced_with_presence_heartbeats(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'student-tracking']);

        $course = Course::query()->create([
            'code' => 'LIVE-300',
            'title' => 'Live Class Capacity Test',
            'slug' => 'live-class-capacity-test',
            'price' => 0,
            'status' => 'published',
        ]);
        $liveClass = LiveClass::query()->create([
            'course_id' => $course->id,
            'title' => 'Capacity Controlled Session',
            'instructor_name' => 'AEC Instructor',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'starts_at' => now()->subMinute(),
            'status' => 'live',
            'capacity' => 1,
        ]);

        $studentA = $this->createUserWithRole('student');
        Enrollment::query()->create([
            'user_id' => $studentA->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $this->actingAs($studentA)
            ->post("/live-classes/{$liveClass->id}/join")
            ->assertOk()
            ->assertSee('Open ZOOM Meeting')
            ->assertSee('active LMS participants');

        $participant = LiveClassParticipant::query()
            ->where('live_class_id', $liveClass->id)
            ->where('user_id', $studentA->id)
            ->firstOrFail();
        $this->assertNull($participant->left_at);
        $this->assertDatabaseHas('student_activity_events', [
            'user_id' => $studentA->id,
            'event_type' => 'live_class_joined',
            'subject_id' => (string) $liveClass->id,
        ]);

        $this->actingAs($studentA)
            ->postJson("/live-classes/{$liveClass->id}/heartbeat")
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('active_count', 1);

        // Refreshing the join page resumes the existing seat instead of consuming another.
        $this->actingAs($studentA)->post("/live-classes/{$liveClass->id}/join")->assertOk();
        $this->assertDatabaseCount('live_class_participants', 1);

        $studentB = $this->createUserWithRole('student');
        Enrollment::query()->create([
            'user_id' => $studentB->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $this->actingAs($studentB)
            ->post("/live-classes/{$liveClass->id}/join")
            ->assertStatus(429);

        $this->actingAs($studentA)
            ->postJson("/live-classes/{$liveClass->id}/leave")
            ->assertOk()
            ->assertJsonPath('left', true);

        $this->actingAs($studentB)
            ->post("/live-classes/{$liveClass->id}/join")
            ->assertOk();

        $this->assertDatabaseHas('live_class_participants', [
            'live_class_id' => $liveClass->id,
            'user_id' => $studentA->id,
        ]);
        $this->assertSame(2, LiveClassParticipant::query()->count());
    }

    public function test_non_enrolled_student_and_non_student_role_cannot_join_live_class(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management']);

        $course = Course::query()->create([
            'code' => 'LIVE-ACCESS',
            'title' => 'Live Access Course',
            'slug' => 'live-access-course',
            'price' => 0,
            'status' => 'published',
        ]);
        $liveClass = LiveClass::query()->create([
            'course_id' => $course->id,
            'title' => 'Private Live Session',
            'platform' => 'teams',
            'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/example',
            'starts_at' => now(),
            'status' => 'live',
            'capacity' => 300,
        ]);

        $student = $this->createUserWithRole('student');
        $this->actingAs($student)->post("/live-classes/{$liveClass->id}/join")->assertForbidden();

        $teacher = $this->createUserWithRole('teacher');
        $this->actingAs($teacher)->post("/live-classes/{$liveClass->id}/join")->assertForbidden();

        $this->assertDatabaseCount('live_class_participants', 0);
    }
}
