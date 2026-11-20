<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

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

        $eventsBeforeRejectedJoin = app('db')->table('student_activity_events')->count();

        $this->actingAs($studentB)
            ->post("/live-classes/{$liveClass->id}/join")
            ->assertStatus(429);

        // A capacity-rejected request must not create a false joined event.
        $this->assertSame(
            $eventsBeforeRejectedJoin,
            app('db')->table('student_activity_events')->count()
        );

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

    public function test_heartbeat_requires_active_enrollment_and_leave_releases_own_seat(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management']);

        $course = Course::query()->create([
            'code' => 'LIVE-HEARTBEAT-AUTH',
            'title' => 'Heartbeat Authorization Course',
            'slug' => 'heartbeat-authorization-course',
            'price' => 0,
            'status' => 'published',
        ]);
        $liveClass = LiveClass::query()->create([
            'course_id' => $course->id,
            'title' => 'Heartbeat Authorization Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'starts_at' => now()->subMinute(),
            'status' => 'live',
            'capacity' => 300,
        ]);

        $student = $this->createUserWithRole('student');
        $enrollment = Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $this->actingAs($student)->post("/live-classes/{$liveClass->id}/join")->assertOk();

        $enrollment->update(['status' => 'cancelled']);
        $this->actingAs($student)
            ->postJson("/live-classes/{$liveClass->id}/heartbeat")
            ->assertForbidden();

        // The owner can still release their own seat after entitlement is revoked.
        $this->actingAs($student)
            ->postJson("/live-classes/{$liveClass->id}/leave")
            ->assertOk()
            ->assertJsonPath('left', true);
    }

    public function test_join_rechecks_cancelled_enrollment_and_inactive_user_before_granting_a_seat(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management']);

        $course = Course::query()->create([
            'code' => 'LIVE-REVOKED-JOIN',
            'title' => 'Revoked Enrollment Join Test',
            'slug' => 'revoked-enrollment-join-test',
            'price' => 0,
            'status' => 'published',
        ]);
        $liveClass = LiveClass::query()->create([
            'course_id' => $course->id,
            'title' => 'Revoked Enrollment Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'starts_at' => now()->subMinute(),
            'status' => 'live',
            'capacity' => 300,
        ]);

        $student = $this->createUserWithRole('student');
        $enrollment = Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $enrollment->update(['status' => 'cancelled']);
        $this->actingAs($student)
            ->post("/live-classes/{$liveClass->id}/join")
            ->assertForbidden();
        $this->assertDatabaseCount('live_class_participants', 0);

        $enrollment->update(['status' => 'active']);
        app('db')->table('users')->where('id', $student->id)->update(['is_active' => false]);

        $this->actingAs($student)
            ->post("/live-classes/{$liveClass->id}/join")
            ->assertForbidden();
        $this->assertDatabaseCount('live_class_participants', 0);
    }

    public function test_inactive_or_deleted_student_profile_cannot_join_even_with_active_enrollment(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'student-management']);

        $course = Course::query()->create([
            'code' => 'LIVE-INACTIVE-PROFILE',
            'title' => 'Inactive Profile Live Access Test',
            'slug' => 'inactive-profile-live-access-test',
            'price' => 0,
            'status' => 'published',
        ]);
        $liveClass = LiveClass::query()->create([
            'course_id' => $course->id,
            'title' => 'Inactive Profile Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'starts_at' => now()->subMinute(),
            'status' => 'live',
            'capacity' => 300,
        ]);

        $inactiveStudent = $this->createUserWithRole('student');
        Enrollment::query()->create([
            'user_id' => $inactiveStudent->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
        app('db')->table('students')->insert([
            'user_id' => $inactiveStudent->id,
            'branch_id' => null,
            'student_code' => 'STU-LIVE-INACTIVE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'admission_date' => now()->toDateString(),
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($inactiveStudent)
            ->post("/live-classes/{$liveClass->id}/join")
            ->assertForbidden();

        $deletedStudent = $this->createUserWithRole('student');
        Enrollment::query()->create([
            'user_id' => $deletedStudent->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
        app('db')->table('students')->insert([
            'user_id' => $deletedStudent->id,
            'branch_id' => null,
            'student_code' => 'STU-LIVE-DELETED-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        $this->actingAs($deletedStudent)
            ->post("/live-classes/{$liveClass->id}/join")
            ->assertForbidden();

        $this->assertDatabaseCount('live_class_participants', 0);
    }

    public function test_conflicting_account_and_student_profile_branches_cannot_join_live_class(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'student-management']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'LIVE-BRANCH-A-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Live Access Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'LIVE-BRANCH-B-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Live Access Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $course = Course::query()->create([
            'branch_id' => $branchA,
            'code' => 'LIVE-BRANCH-MISMATCH',
            'title' => 'Branch Mismatch Live Course',
            'slug' => 'branch-mismatch-live-course',
            'price' => 0,
            'status' => 'published',
        ]);
        $liveClass = LiveClass::query()->create([
            'course_id' => $course->id,
            'title' => 'Branch Mismatch Live Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'starts_at' => now()->subMinute(),
            'status' => 'live',
            'capacity' => 300,
        ]);

        // The account belongs to B while its current Student profile still says A.
        $student = $this->createUserWithRole('student', (int) $branchB);
        DB::table('students')->insert([
            'user_id' => $student->id,
            'branch_id' => $branchA,
            'student_code' => 'LIVE-BRANCH-MISMATCH-' . strtoupper(bin2hex(random_bytes(3))),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'branch_id' => $branchA,
            'status' => 'active',
        ]);

        $this->actingAs($student)
            ->post('/live-classes/' . $liveClass->id . '/join')
            ->assertForbidden();

        $this->assertDatabaseCount('live_class_participants', 0);
        $this->assertDatabaseMissing('student_activity_events', [
            'user_id' => $student->id,
            'event_type' => 'live_class_joined',
            'subject_id' => (string) $liveClass->id,
        ]);
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

    public function test_expired_heartbeat_cannot_reclaim_a_full_live_class_seat(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management']);

        $course = Course::query()->create([
            'code' => 'LIVE-STALE-HEARTBEAT',
            'title' => 'Stale Heartbeat Capacity Test',
            'slug' => 'stale-heartbeat-capacity-test',
            'price' => 0,
            'status' => 'published',
        ]);
        $liveClass = LiveClass::query()->create([
            'course_id' => $course->id,
            'title' => 'Single Seat Stale Heartbeat Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'starts_at' => now()->subMinute(),
            'status' => 'live',
            'capacity' => 1,
        ]);

        $studentA = $this->createUserWithRole('student');
        $studentB = $this->createUserWithRole('student');
        foreach ([$studentA, $studentB] as $student) {
            Enrollment::query()->create([
                'user_id' => $student->id,
                'course_id' => $course->id,
                'status' => 'active',
            ]);
        }

        $this->actingAs($studentA)->post("/live-classes/{$liveClass->id}/join")->assertOk();
        LiveClassParticipant::query()
            ->where('live_class_id', $liveClass->id)
            ->where('user_id', $studentA->id)
            ->update(['last_seen_at' => now()->subMinutes(3)]);

        // Student B takes the seat after A's two-minute presence lease expires.
        $this->actingAs($studentB)->post("/live-classes/{$liveClass->id}/join")->assertOk();

        // A stale heartbeat must not silently reclaim the now-occupied seat.
        $this->actingAs($studentA)
            ->postJson("/live-classes/{$liveClass->id}/heartbeat")
            ->assertStatus(429);

        $this->assertSame(
            1,
            LiveClassParticipant::query()
                ->where('live_class_id', $liveClass->id)
                ->whereNull('left_at')
                ->where('last_seen_at', '>=', now()->subMinutes(2))
                ->count()
        );
    }

    public function test_mysql_join_waits_for_account_revocation_then_denies_after_commit(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This row-lock contention regression requires MySQL 8.');
        }

        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'student-management']);

        $course = Course::query()->create([
            'code' => 'LIVE-MYSQL-REVOKE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'title' => 'MySQL Revocation Race Course',
            'slug' => 'live-mysql-revoke-' . strtolower(substr(bin2hex(random_bytes(3)), 0, 6)),
            'price' => 0,
            'status' => 'published',
        ]);
        $liveClass = LiveClass::query()->create([
            'course_id' => $course->id,
            'title' => 'MySQL Revocation Race Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'starts_at' => now()->subMinute(),
            'status' => 'live',
            'capacity' => 300,
        ]);
        $student = $this->createUserWithRole('student');
        DB::table('students')->insert([
            'user_id' => $student->id,
            'branch_id' => null,
            'student_code' => 'STU-LIVE-MYSQL-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $firstConnection = DB::connection('mysql');
        $secondName = 'mysql_live_class_concurrency';
        config(['database.connections.' . $secondName => config('database.connections.mysql')]);
        DB::purge($secondName);
        $secondConnection = DB::connection($secondName);
        $secondConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $defaultConnection = DB::getDefaultConnection();
        $firstConnection->beginTransaction();

        try {
            // Simulate the lifecycle writer holding the common account sentinel
            // while it commits a profile/account deactivation.
            $firstConnection->table('users')->where('id', $student->id)->lockForUpdate()->first();
            $firstConnection->table('users')->where('id', $student->id)->update(['is_active' => false]);
            $firstConnection->table('students')->where('user_id', $student->id)->update(['status' => 'inactive']);

            DB::setDefaultConnection($secondName);
            try {
                $this->withoutExceptionHandling();
                $this->actingAs($student)->post('/live-classes/' . $liveClass->id . '/join');
                $this->fail('Join must not pass the account row lock while revocation is in progress.');
            } catch (\Illuminate\Database\QueryException $exception) {
                $this->assertStringContainsString('lock wait timeout', strtolower($exception->getMessage()));
            } finally {
                $this->withExceptionHandling();
            }

            DB::setDefaultConnection('mysql');
            $firstConnection->commit();

            DB::setDefaultConnection($secondName);
            $this->actingAs($student)
                ->post('/live-classes/' . $liveClass->id . '/join')
                ->assertForbidden();

            $this->assertSame(0, $secondConnection->table('live_class_participants')
                ->where('live_class_id', $liveClass->id)
                ->where('user_id', $student->id)
                ->whereNull('left_at')
                ->count());
        } finally {
            DB::setDefaultConnection('mysql');
            if ($firstConnection->transactionLevel() > 0) {
                $firstConnection->rollBack();
            }
            DB::setDefaultConnection($defaultConnection);
            DB::purge($secondName);
        }
    }


}
