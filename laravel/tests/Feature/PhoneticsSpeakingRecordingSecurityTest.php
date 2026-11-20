<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\Phonetics\Models\PhoneticEntry;
use Plugins\Modules\Phonetics\Models\PhoneticSetting;
use Plugins\Modules\Phonetics\Models\PhoneticSpeakingRecording;
use Tests\TestCase;

final class PhoneticsSpeakingRecordingSecurityTest extends TestCase
{
    public function test_branch_manager_can_only_review_recordings_from_their_own_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'phonetics']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'PHON-REV-A-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Recording Review Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'PHON-REV-B-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Recording Review Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student = $this->createUserWithRole('student');
        $student->update(['branch_id' => $branchB]);

        $course = [
            'code' => 'PHON-REVIEW-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'title' => 'British and American Phonetics',
            'slug' => 'british-american-phonetics',
            'description' => 'Speaking review authorization test',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('courses', 'branch_id')) {
            $course['branch_id'] = $branchB;
        }
        $courseId = DB::table('courses')->insertGetId($course);

        PhoneticSetting::query()->firstOrCreate(['id' => 1], [
            'enabled' => true,
            'course_slug' => 'british-american-phonetics',
            'course_title' => 'British & American Phonetics',
        ]);
        PhoneticSetting::query()->whereKey(1)->update([
            'enabled' => true,
            'course_slug' => 'british-american-phonetics',
        ]);
        Cache::forget('phonetics.settings');

        $entry = PhoneticEntry::query()->create([
            'word' => 'branch-review-test',
            'category' => 'rhoticity',
            'uk_ipa' => '/test/',
            'us_ipa' => '/test/',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $recording = PhoneticSpeakingRecording::query()->create([
            'lms_driver' => 'aec',
            'user_id' => (string) $student->getAuthIdentifier(),
            'entry_id' => $entry->id,
            'course_ref' => (string) $courseId,
            'storage_path' => 'students/test/private-recording.webm',
            'mime_type' => 'audio/webm',
            'size_bytes' => 128,
            'reported_duration_seconds' => 8,
            'status' => 'pending',
        ]);

        $permissionId = DB::table('permissions')->where('slug', 'phonetics.manage')->value('id');
        $this->assertNotNull($permissionId);
        $managerA = $this->createUserWithRole('branch_manager', (int) $branchA);
        $managerA->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);
        $managerB = $this->createUserWithRole('branch_manager', (int) $branchB);
        $managerB->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        $this->actingAs($managerA)
            ->patch('/admin/phonetics/learning/recordings/' . $recording->id . '/review', [
                'score' => 4,
                'feedback' => 'Good clarity. Practise the final consonant release.',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('phonetic_speaking_recordings', [
            'id' => $recording->id,
            'status' => 'pending',
            'score' => null,
        ]);

        $this->actingAs($managerB)
            ->patch('/admin/phonetics/learning/recordings/' . $recording->id . '/review', [
                'score' => 5,
                'feedback' => 'Clear pronunciation with accurate articulation.',
            ])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('phonetic_speaking_recordings', [
            'id' => $recording->id,
            'status' => 'reviewed',
            'score' => 5,
            'reviewer_user_id' => (string) $managerB->getAuthIdentifier(),
        ]);

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Final-write reviewer race checks require MySQL row-lock semantics.');
        }

        // Simulate an account branch reassignment after the initial controller
        // authorization but immediately after the recording row is locked.
        $branchRaceRecording = PhoneticSpeakingRecording::query()->create([
            'lms_driver' => 'aec',
            'user_id' => (string) $student->getAuthIdentifier(),
            'entry_id' => $entry->id,
            'course_ref' => (string) $courseId,
            'storage_path' => 'students/test/branch-race-recording.webm',
            'mime_type' => 'audio/webm',
            'size_bytes' => 128,
            'reported_duration_seconds' => 8,
            'status' => 'pending',
        ]);
        $managerC = $this->createUserWithRole('branch_manager', (int) $branchB);
        $managerC->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);
        $branchChanged = false;
        DB::listen(function ($query) use (&$branchChanged, $managerC, $branchA): void {
            $sql = strtolower($query->sql);
            if (!$branchChanged && str_contains($sql, 'phonetic_speaking_recordings') && str_contains($sql, 'for update')) {
                DB::table('users')->where('id', $managerC->getKey())->update(['branch_id' => $branchA]);
                $branchChanged = true;
            }
        });
        $this->actingAs($managerC)
            ->patch('/admin/phonetics/learning/recordings/' . $branchRaceRecording->id . '/review', [
                'score' => 4,
                'feedback' => 'This write must fail after reviewer branch reassignment.',
            ])
            ->assertNotFound();
        $this->assertTrue($branchChanged, 'The branch reassignment hook must run after the recording lock.');
        $this->assertDatabaseHas('phonetic_speaking_recordings', [
            'id' => $branchRaceRecording->id,
            'status' => 'pending',
            'score' => null,
        ]);

        // Repeat the race for account deactivation. The preflight middleware
        // sees an active reviewer; the final transactional gate must still
        // reject the write after the account is deactivated.
        $inactiveRaceRecording = PhoneticSpeakingRecording::query()->create([
            'lms_driver' => 'aec',
            'user_id' => (string) $student->getAuthIdentifier(),
            'entry_id' => $entry->id,
            'course_ref' => (string) $courseId,
            'storage_path' => 'students/test/inactive-reviewer-race.webm',
            'mime_type' => 'audio/webm',
            'size_bytes' => 128,
            'reported_duration_seconds' => 8,
            'status' => 'pending',
        ]);
        $managerD = $this->createUserWithRole('branch_manager', (int) $branchB);
        $managerD->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);
        $reviewerDeactivated = false;
        DB::listen(function ($query) use (&$reviewerDeactivated, $managerD): void {
            $sql = strtolower($query->sql);
            if (!$reviewerDeactivated && str_contains($sql, 'phonetic_speaking_recordings') && str_contains($sql, 'for update')) {
                DB::table('users')->where('id', $managerD->getKey())->update(['is_active' => false]);
                $reviewerDeactivated = true;
            }
        });
        $this->actingAs($managerD)
            ->patch('/admin/phonetics/learning/recordings/' . $inactiveRaceRecording->id . '/review', [
                'score' => 4,
                'feedback' => 'This write must fail after reviewer deactivation.',
            ])
            ->assertForbidden();
        $this->assertTrue($reviewerDeactivated, 'The reviewer deactivation hook must run after the recording lock.');
        $this->assertDatabaseHas('phonetic_speaking_recordings', [
            'id' => $inactiveRaceRecording->id,
            'status' => 'pending',
            'score' => null,
        ]);

        // Permission revocation after the request-time gate and after the
        // recording lock must be observed by the final transactional check.
        $permissionRaceRecording = PhoneticSpeakingRecording::query()->create([
            'lms_driver' => 'aec',
            'user_id' => (string) $student->getAuthIdentifier(),
            'entry_id' => $entry->id,
            'course_ref' => (string) $courseId,
            'storage_path' => 'students/test/permission-revocation-race.webm',
            'mime_type' => 'audio/webm',
            'size_bytes' => 128,
            'reported_duration_seconds' => 8,
            'status' => 'pending',
        ]);
        $managerE = $this->createUserWithRole('branch_manager', (int) $branchB);
        $reviewRole = $managerE->roles()->firstOrFail();
        $reviewRole->permissions()->syncWithoutDetaching([$permissionId]);
        $permissionRevoked = false;
        DB::listen(function ($query) use (&$permissionRevoked, $reviewRole, $permissionId): void {
            $sql = strtolower($query->sql);
            if (
                !$permissionRevoked
                && str_contains($sql, 'phonetic_speaking_recordings')
                && str_contains($sql, 'for update')
            ) {
                DB::table('permission_role')
                    ->where('role_id', $reviewRole->getKey())
                    ->where('permission_id', $permissionId)
                    ->delete();
                $permissionRevoked = true;
            }
        });
        $auditCountBefore = DB::table('phonetic_audit_events')
            ->where('entity_type', 'speaking_recording')
            ->where('entity_id', (string) $permissionRaceRecording->id)
            ->count();
        $this->actingAs($managerE)
            ->patch('/admin/phonetics/learning/recordings/' . $permissionRaceRecording->id . '/review', [
                'score' => 4,
                'feedback' => 'This write must fail after permission revocation.',
            ])
            ->assertForbidden();
        $this->assertTrue($permissionRevoked, 'Permission revocation must occur after the recording lock.');
        $this->assertDatabaseHas('phonetic_speaking_recordings', [
            'id' => $permissionRaceRecording->id,
            'status' => 'pending',
            'score' => null,
            'feedback' => null,
        ]);
        $this->assertSame(
            $auditCountBefore,
            DB::table('phonetic_audit_events')
                ->where('entity_type', 'speaking_recording')
                ->where('entity_id', (string) $permissionRaceRecording->id)
                ->count(),
            'A denied review must not create an audit event.'
        );
    }
    public function test_branch_review_queue_hides_same_branch_student_recordings_for_other_branch_courses(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'phonetics']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'PHON-QUEUE-A-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Queue Reviewer Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'PHON-QUEUE-B-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Queue Course Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student = $this->createUserWithRole('student', (int) $branchA);
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'PHON-QUEUE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'title' => 'Other Branch Phonetics Course',
            'slug' => 'british-american-phonetics',
            'description' => 'Queue isolation test',
            'price' => 0,
            'status' => 'published',
            'branch_id' => $branchB,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        PhoneticSetting::query()->firstOrCreate(['id' => 1], [
            'enabled' => true,
            'course_slug' => 'british-american-phonetics',
            'course_title' => 'British & American Phonetics',
        ]);
        PhoneticSetting::query()->whereKey(1)->update([
            'enabled' => true,
            'course_slug' => 'british-american-phonetics',
        ]);
        Cache::forget('phonetics.settings');

        $entry = PhoneticEntry::query()->create([
            'word' => 'queue-branch-leak-word',
            'category' => 'rhoticity',
            'uk_ipa' => '/test/',
            'us_ipa' => '/test/',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $recording = PhoneticSpeakingRecording::query()->create([
            'lms_driver' => 'aec',
            'user_id' => (string) $student->getAuthIdentifier(),
            'entry_id' => $entry->id,
            'course_ref' => (string) $courseId,
            'storage_path' => 'students/test/other-branch-course.webm',
            'mime_type' => 'audio/webm',
            'size_bytes' => 128,
            'reported_duration_seconds' => 8,
            'status' => 'pending',
        ]);

        $permissionId = DB::table('permissions')->where('slug', 'phonetics.manage')->value('id');
        $this->assertNotNull($permissionId);
        $manager = $this->createUserWithRole('branch_manager', (int) $branchA);
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        $this->actingAs($manager)
            ->get('/admin/phonetics/learning/recordings')
            ->assertOk()
            ->assertDontSee('queue-branch-leak-word')
            ->assertDontSee('Student ID: ' . $student->getAuthIdentifier());

        $this->actingAs($manager)
            ->get('/admin/phonetics/learning/recordings/' . $recording->id . '/audio')
            ->assertNotFound();

        $this->actingAs($manager)
            ->patch('/admin/phonetics/learning/recordings/' . $recording->id . '/review', [
                'score' => 4,
                'feedback' => 'This should never be accepted across course branches.',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('phonetic_speaking_recordings', [
            'id' => $recording->id,
            'status' => 'pending',
            'score' => null,
        ]);
    }


    public function test_mysql_permission_pivot_lock_blocks_concurrent_revocation_until_review_commits(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Permission-pivot contention requires MySQL 8 row locks.');
        }

        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'phonetics']);

        $branch = DB::table('branches')->insertGetId([
            'code' => 'PHON-PIVOT-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Permission Pivot Lock Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $student = $this->createUserWithRole('student', (int) $branch);
        $reviewer = $this->createUserWithRole('branch_manager', (int) $branch);

        $courseId = DB::table('courses')->insertGetId([
            'code' => 'PHON-PIVOT-COURSE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'title' => 'Permission Pivot Lock Course',
            'slug' => 'phonetics-pivot-' . strtolower(substr(bin2hex(random_bytes(3)), 0, 6)),
            'description' => 'MySQL authorization pivot lock test',
            'price' => 0,
            'status' => 'published',
            'branch_id' => $branch,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        PhoneticSetting::query()->firstOrCreate(['id' => 1], [
            'enabled' => true,
            'course_slug' => 'british-american-phonetics',
            'course_title' => 'British & American Phonetics',
        ]);
        PhoneticSetting::query()->whereKey(1)->update(['enabled' => true, 'course_slug' => 'british-american-phonetics']);
        Cache::forget('phonetics.settings');

        $entry = PhoneticEntry::query()->create([
            'word' => 'pivot-lock-test-word',
            'category' => 'rhoticity',
            'uk_ipa' => '/test/',
            'us_ipa' => '/test/',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $recording = PhoneticSpeakingRecording::query()->create([
            'lms_driver' => 'aec',
            'user_id' => (string) $student->id,
            'entry_id' => $entry->id,
            'course_ref' => (string) $courseId,
            'storage_path' => 'students/test/permission-pivot-lock.webm',
            'mime_type' => 'audio/webm',
            'size_bytes' => 128,
            'reported_duration_seconds' => 8,
            'status' => 'pending',
        ]);

        $permissionId = DB::table('permissions')->where('slug', 'phonetics.manage')->value('id');
        $this->assertNotNull($permissionId);
        $reviewRole = $reviewer->roles()->firstOrFail();
        $reviewRole->permissions()->syncWithoutDetaching([$permissionId]);

        $secondName = 'mysql_phonetics_permission_revoke';
        config(['database.connections.' . $secondName => config('database.connections.mysql')]);
        DB::purge($secondName);
        $secondConnection = DB::connection($secondName);
        $secondConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $revocationBlocked = false;
        $revocationAttempted = false;
        DB::listen(function ($query) use (&$revocationBlocked, &$revocationAttempted, $secondConnection, $reviewRole, $permissionId): void {
            $sql = strtolower($query->sql);
            if (
                $revocationAttempted
                || !str_contains($sql, 'permission_role')
                || !str_contains($sql, 'for update')
            ) {
                return;
            }

            // This callback runs after the review transaction has acquired the
            // permission_role row lock. A second DB connection now attempts the
            // exact revoke operation and must be blocked until review commits.
            $revocationAttempted = true;
            try {
                $secondConnection->table('permission_role')
                    ->where('role_id', $reviewRole->getKey())
                    ->where('permission_id', $permissionId)
                    ->delete();
            } catch (\Illuminate\Database\QueryException $exception) {
                $revocationBlocked = str_contains(strtolower($exception->getMessage()), 'lock wait timeout');
                if (!$revocationBlocked) {
                    throw $exception;
                }
            }
        });

        try {
            $response = $this->actingAs($reviewer)
                ->patch('/admin/phonetics/learning/recordings/' . $recording->id . '/review', [
                    'score' => 5,
                    'feedback' => 'Review completed while the permission pivot was locked.',
                ]);

            $sessionData = app('session.store')->all();
            $responseDetails = sprintf(
                'Review response diagnostics: HTTP %d; Location=%s; Session keys=%s; Body=%s',
                $response->getStatusCode(),
                (string) $response->headers->get('Location', ''),
                json_encode(array_keys($sessionData), JSON_UNESCAPED_SLASHES),
                substr(trim(strip_tags($response->getContent())), 0, 600)
            );
            // Emit the diagnostic immediately so a later suite timeout cannot
            // hide the original authorization/session response from CI logs.
            fwrite(STDERR, $responseDetails . PHP_EOL);
            $this->assertSame(302, $response->getStatusCode(), $responseDetails);
            $this->assertArrayHasKey('status', $sessionData, $responseDetails);

            $this->assertTrue($revocationAttempted, 'The second connection must attempt revocation after the final permission lock.');
            $this->assertTrue($revocationBlocked, 'Concurrent permission revocation must wait for the review transaction.');
            $this->assertDatabaseHas('phonetic_speaking_recordings', [
                'id' => $recording->id,
                'status' => 'reviewed',
                'score' => 5,
            ]);

            // Once the review commits, the waiting revoke is now allowed.
            $deleted = $secondConnection->table('permission_role')
                ->where('role_id', $reviewRole->getKey())
                ->where('permission_id', $permissionId)
                ->delete();
            $this->assertSame(1, $deleted);

            $nextRecording = PhoneticSpeakingRecording::query()->create([
                'lms_driver' => 'aec',
                'user_id' => (string) $student->id,
                'entry_id' => $entry->id,
                'course_ref' => (string) $courseId,
                'storage_path' => 'students/test/permission-revoked-after-lock.webm',
                'mime_type' => 'audio/webm',
                'size_bytes' => 128,
                'reported_duration_seconds' => 8,
                'status' => 'pending',
            ]);
            $auditBefore = DB::table('phonetic_audit_events')
                ->where('entity_type', 'speaking_recording')
                ->where('entity_id', (string) $nextRecording->id)
                ->count();

            $this->actingAs($reviewer)
                ->patch('/admin/phonetics/learning/recordings/' . $nextRecording->id . '/review', [
                    'score' => 4,
                    'feedback' => 'Must be denied after the permission revoke commits.',
                ])
                ->assertForbidden();

            $this->assertDatabaseHas('phonetic_speaking_recordings', [
                'id' => $nextRecording->id,
                'status' => 'pending',
                'score' => null,
                'feedback' => null,
            ]);
            $this->assertSame($auditBefore, DB::table('phonetic_audit_events')
                ->where('entity_type', 'speaking_recording')
                ->where('entity_id', (string) $nextRecording->id)
                ->count());
        } finally {
            DB::purge($secondName);
        }
    }


}
