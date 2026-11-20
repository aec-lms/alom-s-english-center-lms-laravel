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

    public function test_conflicting_account_and_student_profile_branches_deny_join_and_heartbeat_in_both_directions(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'student-management', 'student-tracking']);

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

        // Direction one: account B / Student profile A.
        $accountBStudent = $this->createUserWithRole('student', (int) $branchB);
        DB::table('students')->insert([
            'user_id' => $accountBStudent->id,
            'branch_id' => $branchA,
            'student_code' => 'LIVE-BRANCH-BA-' . strtoupper(bin2hex(random_bytes(3))),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Direction two: account A / Student profile B.
        $accountAStudent = $this->createUserWithRole('student', (int) $branchA);
        DB::table('students')->insert([
            'user_id' => $accountAStudent->id,
            'branch_id' => $branchB,
            'student_code' => 'LIVE-BRANCH-AB-' . strtoupper(bin2hex(random_bytes(3))),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$accountBStudent, $accountAStudent] as $student) {
            Enrollment::query()->create([
                'user_id' => $student->id,
                'course_id' => $course->id,
                'branch_id' => $branchA,
                'status' => 'active',
            ]);
        }

        $lastSeen = now()->subSeconds(30);
        foreach ([$accountBStudent, $accountAStudent] as $student) {
            LiveClassParticipant::query()->create([
                'live_class_id' => $liveClass->id,
                'user_id' => $student->id,
                'joined_at' => $lastSeen,
                'last_seen_at' => $lastSeen,
                'left_at' => null,
            ]);
        }

        foreach ([$accountBStudent, $accountAStudent] as $student) {
            $eventsBefore = DB::table('student_activity_events')
                ->where('user_id', $student->id)
                ->where('subject_id', (string) $liveClass->id)
                ->whereIn('event_type', ['live_class_joined', 'live_class_heartbeat'])
                ->count();

            $this->actingAs($student)
                ->post('/live-classes/' . $liveClass->id . '/join')
                ->assertForbidden();
            $this->actingAs($student)
                ->postJson('/live-classes/' . $liveClass->id . '/heartbeat')
                ->assertForbidden();

            $participant = LiveClassParticipant::query()
                ->where('live_class_id', $liveClass->id)
                ->where('user_id', $student->id)
                ->firstOrFail();
            $this->assertNull($participant->left_at);
            $this->assertSame(
                $lastSeen->toDateTimeString(),
                $participant->last_seen_at->toDateTimeString(),
                'A denied join/heartbeat must not refresh or release the existing participant seat.'
            );

            $eventsAfter = DB::table('student_activity_events')
                ->where('user_id', $student->id)
                ->where('subject_id', (string) $liveClass->id)
                ->whereIn('event_type', ['live_class_joined', 'live_class_heartbeat'])
                ->count();
            $this->assertSame($eventsBefore, $eventsAfter, 'A denied join/heartbeat must not emit a new activity event.');
        }

        $this->assertSame(2, LiveClassParticipant::query()
            ->where('live_class_id', $liveClass->id)
            ->whereNull('left_at')
            ->count());
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

    public function test_mysql_role_revocation_waits_for_heartbeat_lock_and_next_heartbeat_keeps_seat_unchanged(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This role-pivot row-lock regression requires MySQL 8.');
        }

        [$student, $liveClass, $participant] = $this->createLiveClassConcurrencyFixture();
        // Preserve a stale authenticated role relation to model the request
        // that passed middleware before the administrator revoked the pivot.
        $student->load('roles');

        $primaryName = DB::getDefaultConnection();
        $primary = DB::connection($primaryName);
        $roleId = DB::table('roles')->where('slug', 'student')->value('id');
        $this->assertNotNull($roleId);

        $primary->table('live_class_participants')
            ->where('id', $participant->id)
            ->update(['last_seen_at' => now()->subSeconds(15)]);
        $primary->beginTransaction();

        try {
            // The protected heartbeat owns User -> role pivot -> profile and
            // participant locks until the outer transaction commits.
            $this->actingAs($student)
                ->postJson('/live-classes/' . $liveClass->id . '/heartbeat')
                ->assertOk();
            $heartbeatEventsBefore = $primary->table('student_activity_events')
                ->where('user_id', $student->id)
                ->where('event_type', 'live_class_heartbeat')
                ->where('subject_id', (string) $liveClass->id)
                ->count();
            $lastSeenAfterHeartbeat = $primary->table('live_class_participants')
                ->where('id', $participant->id)
                ->value('last_seen_at');

            $secondaryName = $this->createSecondaryMysqlConnection($primaryName);
            $secondary = DB::connection($secondaryName);
            $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');

            DB::setDefaultConnection($secondaryName);
            try {
                $secondary->table('role_user')
                    ->where('user_id', $student->id)
                    ->where('role_id', $roleId)
                    ->delete();
                $this->fail('Role revocation must wait for the protected heartbeat transaction.');
            } catch (\Illuminate\Database\QueryException $exception) {
                $this->assertContains((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], $exception->getMessage());
            }

            DB::setDefaultConnection($primaryName);
            $primary->commit();

            DB::setDefaultConnection($secondaryName);
            $secondary->table('role_user')
                ->where('user_id', $student->id)
                ->where('role_id', $roleId)
                ->delete();

            $this->actingAs($student)
                ->postJson('/live-classes/' . $liveClass->id . '/heartbeat')
                ->assertForbidden();

            $unchanged = $secondary->table('live_class_participants')->where('id', $participant->id)->first();
            $this->assertNull($unchanged->left_at);
            $this->assertSame(
                (string) $lastSeenAfterHeartbeat,
                (string) $unchanged->last_seen_at,
                'A heartbeat denied after role revocation must not refresh or release the existing seat.'
            );
            $heartbeatEventsAfter = $secondary->table('student_activity_events')
                ->where('user_id', $student->id)
                ->where('event_type', 'live_class_heartbeat')
                ->where('subject_id', (string) $liveClass->id)
                ->count();
            $this->assertSame($heartbeatEventsBefore, $heartbeatEventsAfter);

            DB::setDefaultConnection($primaryName);
            DB::purge($secondaryName);
        } catch (\Throwable $exception) {
            DB::setDefaultConnection($primaryName);
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            throw $exception;
        }
    }

    public function test_mysql_heartbeat_waits_for_account_deactivation_then_denies_without_refreshing_seat(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Account deactivation row-lock contention requires MySQL 8.');
        }

        [$student, $liveClass, $participant] = $this->createLiveClassConcurrencyFixture();
        $primaryName = DB::getDefaultConnection();
        $primary = DB::connection($primaryName);
        $primary->table('live_class_participants')
            ->where('id', $participant->id)
            ->update(['last_seen_at' => now()->subSeconds(15)]);
        $eventsBefore = $primary->table('student_activity_events')
            ->where('user_id', $student->id)
            ->where('event_type', 'live_class_heartbeat')
            ->where('subject_id', (string) $liveClass->id)
            ->count();
        $lastSeenBefore = $primary->table('live_class_participants')
            ->where('id', $participant->id)
            ->value('last_seen_at');

        $secondaryName = $this->createSecondaryMysqlConnection($primaryName);
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $primary->beginTransaction();
        try {
            $primary->table('users')->where('id', $student->id)->lockForUpdate()->first();
            $primary->table('users')->where('id', $student->id)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

            DB::setDefaultConnection($secondaryName);
            $this->assertSame($secondaryName, DB::connection()->getName());
            $this->assertHeartbeatWaitsForMysqlLock($student, $liveClass, 'account deactivation');

            DB::setDefaultConnection($primaryName);
            $primary->commit();

            DB::setDefaultConnection($secondaryName);
            $this->actingAs($student)
                ->postJson('/live-classes/' . $liveClass->id . '/heartbeat')
                ->assertForbidden();

            $unchanged = $secondary->table('live_class_participants')->where('id', $participant->id)->first();
            $this->assertNull($unchanged->left_at);
            $this->assertSame((string) $lastSeenBefore, (string) $unchanged->last_seen_at);
            $this->assertFalse((bool) $secondary->table('users')->where('id', $student->id)->value('is_active'));
            $eventsAfter = $secondary->table('student_activity_events')
                ->where('user_id', $student->id)
                ->where('event_type', 'live_class_heartbeat')
                ->where('subject_id', (string) $liveClass->id)
                ->count();
            $this->assertSame($eventsBefore, $eventsAfter);
        } catch (\Throwable $exception) {
            DB::setDefaultConnection($primaryName);
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            throw $exception;
        } finally {
            DB::setDefaultConnection($primaryName);
            DB::purge($secondaryName);
        }
    }

    public function test_mysql_heartbeat_waits_for_enrollment_revocation_then_denies_without_refreshing_seat(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This entitlement row-lock regression requires MySQL 8.');
        }

        [$student, $liveClass, $participant] = $this->createLiveClassConcurrencyFixture();
        $primaryName = DB::getDefaultConnection();
        $primary = DB::connection($primaryName);
        $enrollment = Enrollment::query()
            ->where('user_id', $student->id)
            ->where('course_id', $liveClass->course_id)
            ->firstOrFail();

        $primary->table('live_class_participants')
            ->where('id', $participant->id)
            ->update(['last_seen_at' => now()->subSeconds(15)]);
        $heartbeatEventsBefore = $primary->table('student_activity_events')
            ->where('user_id', $student->id)
            ->where('event_type', 'live_class_heartbeat')
            ->where('subject_id', (string) $liveClass->id)
            ->count();
        $expectedLastSeen = $primary->table('live_class_participants')
            ->where('id', $participant->id)
            ->value('last_seen_at');

        $secondaryName = $this->createSecondaryMysqlConnection($primaryName);
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $primary->beginTransaction();
        try {
            // Lock the same lifecycle chain before cancelling enrollment.
            $primary->table('users')->where('id', $student->id)->lockForUpdate()->first();
            $primary->table('role_user')->where('user_id', $student->id)->lockForUpdate()->get();
            $primary->table('students')->where('user_id', $student->id)->lockForUpdate()->first();
            $primary->table('enrollments')->where('id', $enrollment->id)->lockForUpdate()->first();
            $primary->table('enrollments')->where('id', $enrollment->id)->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);

            DB::setDefaultConnection($secondaryName);
            $this->assertSame($secondaryName, DB::connection()->getName());
            $this->assertHeartbeatWaitsForMysqlLock($student, $liveClass, 'enrollment revocation');

            DB::setDefaultConnection($primaryName);
            $primary->commit();

            DB::setDefaultConnection($secondaryName);
            $this->actingAs($student)
                ->postJson('/live-classes/' . $liveClass->id . '/heartbeat')
                ->assertForbidden();

            $unchanged = $secondary->table('live_class_participants')->where('id', $participant->id)->first();
            $this->assertNull($unchanged->left_at);
            $this->assertSame((string) $expectedLastSeen, (string) $unchanged->last_seen_at);
            $this->assertSame('cancelled', $secondary->table('enrollments')->where('id', $enrollment->id)->value('status'));
            $heartbeatEventsAfter = $secondary->table('student_activity_events')
                ->where('user_id', $student->id)
                ->where('event_type', 'live_class_heartbeat')
                ->where('subject_id', (string) $liveClass->id)
                ->count();
            $this->assertSame($heartbeatEventsBefore, $heartbeatEventsAfter);

            DB::setDefaultConnection($primaryName);
            DB::purge($secondaryName);
        } catch (\Throwable $exception) {
            DB::setDefaultConnection($primaryName);
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            throw $exception;
        }
    }

    public function test_mysql_heartbeat_waits_for_paid_live_class_entitlement_revocation_then_denies_without_refreshing_seat(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paid live-class entitlement row-lock contention requires MySQL 8.');
        }

        [$student, $liveClass, $participant] = $this->createLiveClassConcurrencyFixture(true);
        $primaryName = DB::getDefaultConnection();
        $primary = DB::connection($primaryName);
        $secondaryName = $this->createSecondaryMysqlConnection($primaryName);
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $orderId = DB::table('commerce_orders')->insertGetId([
            'order_number' => 'LIVE-PASS-' . strtoupper(bin2hex(random_bytes(4))),
            'user_id' => $student->id,
            'currency' => 'BDT',
            'subtotal' => 100,
            'discount_total' => 0,
            'grand_total' => 100,
            'status' => 'completed',
            'payment_status' => 'paid',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemId = DB::table('commerce_order_items')->insertGetId([
            'order_id' => $orderId,
            'item_type' => 'live_class',
            'item_id' => $liveClass->id,
            'title_snapshot' => 'Paid live class pass',
            'quantity' => 1,
            'unit_price' => 100,
            'line_total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $entitlementId = DB::table('commerce_entitlements')->insertGetId([
            'user_id' => $student->id,
            'order_item_id' => $itemId,
            'entitlement_type' => 'live_class',
            'resource_id' => $liveClass->id,
            'details' => json_encode(['source' => 'test'], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $primary->table('enrollments')
            ->where('user_id', $student->id)
            ->where('course_id', $liveClass->course_id)
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
        $primary->table('live_class_participants')
            ->where('id', $participant->id)
            ->update(['last_seen_at' => now()->subSeconds(15)]);
        $expectedLastSeen = $primary->table('live_class_participants')
            ->where('id', $participant->id)
            ->value('last_seen_at');
        $eventsBefore = $primary->table('student_activity_events')
            ->where('user_id', $student->id)
            ->where('event_type', 'live_class_heartbeat')
            ->where('subject_id', (string) $liveClass->id)
            ->count();

        $primary->beginTransaction();
        try {
            // Hold the real paid-pass row while revoking it. The heartbeat must
            // serialize on this exact commerce entitlement, not enrollment.
            $primary->table('users')->where('id', $student->id)->lockForUpdate()->first();
            $primary->table('role_user')->where('user_id', $student->id)->lockForUpdate()->get();
            $primary->table('students')->where('user_id', $student->id)->lockForUpdate()->first();
            $primary->table('commerce_entitlements')->where('id', $entitlementId)->lockForUpdate()->first();
            $primary->table('commerce_entitlements')->where('id', $entitlementId)->update([
                'status' => 'revoked',
                'updated_at' => now(),
            ]);

            DB::setDefaultConnection($secondaryName);
            $this->assertSame($secondaryName, DB::connection()->getName());
            $this->assertHeartbeatWaitsForMysqlLock($student, $liveClass, 'paid commerce entitlement revocation');

            DB::setDefaultConnection($primaryName);
            $primary->commit();

            DB::setDefaultConnection($secondaryName);
            $this->actingAs($student)
                ->postJson('/live-classes/' . $liveClass->id . '/heartbeat')
                ->assertForbidden();

            $unchanged = $secondary->table('live_class_participants')->where('id', $participant->id)->first();
            $this->assertNull($unchanged->left_at);
            $this->assertSame((string) $expectedLastSeen, (string) $unchanged->last_seen_at);
            $this->assertSame('revoked', $secondary->table('commerce_entitlements')->where('id', $entitlementId)->value('status'));
            $eventsAfter = $secondary->table('student_activity_events')
                ->where('user_id', $student->id)
                ->where('event_type', 'live_class_heartbeat')
                ->where('subject_id', (string) $liveClass->id)
                ->count();
            $this->assertSame($eventsBefore, $eventsAfter);
        } finally {
            DB::setDefaultConnection($primaryName);
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::setDefaultConnection($primaryName);
            DB::purge($secondaryName);
        }
    }

    private function assertHeartbeatWaitsForMysqlLock(User $student, LiveClass $liveClass, string $reason): void
    {
        // Laravel's HTTP test kernel normally renders a QueryException as a
        // generic 500 response. Disable exception handling for this request so
        // the test can assert the actual InnoDB lock-timeout/deadlock code.
        $this->withoutExceptionHandling();

        try {
            $response = $this->actingAs($student)
                ->postJson('/live-classes/' . $liveClass->id . '/heartbeat');

            $this->fail('Heartbeat must wait for ' . $reason . '. Response status=' . $response->status()
                . ' body=' . substr((string) $response->getContent(), 0, 500));
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertContains((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
        }
    }

    private function createLiveClassConcurrencyFixture(bool $withCommerceEntitlements = false): array
    {
        $this->markSystemInstalled();
        $plugins = ['course-management', 'student-management', 'student-tracking'];
        if ($withCommerceEntitlements) {
            $plugins[] = 'commerce-cart';
        }
        $this->activatePlugins($plugins);

        $course = Course::query()->create([
            'code' => 'LIVE-RACE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'title' => 'Live Class Concurrency Course',
            'slug' => 'live-race-' . strtolower(substr(bin2hex(random_bytes(3)), 0, 6)),
            'price' => 0,
            'status' => 'published',
        ]);
        $liveClass = LiveClass::query()->create([
            'course_id' => $course->id,
            'title' => 'Live Class Concurrency Session',
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
            'student_code' => 'LIVE-RACE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
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
        $this->actingAs($student)->post('/live-classes/' . $liveClass->id . '/join')->assertOk();
        $participant = LiveClassParticipant::query()
            ->where('live_class_id', $liveClass->id)
            ->where('user_id', $student->id)
            ->firstOrFail();

        return [$student, $liveClass, $participant];
    }

    private function createSecondaryMysqlConnection(string $primaryName): string
    {
        $primary = DB::connection($primaryName);
        $secondaryName = 'mysql_live_class_' . bin2hex(random_bytes(3));
        $config = config('database.connections.' . $primaryName);
        $config['database'] = $primary->getDatabaseName();
        config(['database.connections.' . $secondaryName => $config]);
        DB::purge($secondaryName);
        DB::connection($secondaryName);

        return $secondaryName;
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
