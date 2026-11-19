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

}
