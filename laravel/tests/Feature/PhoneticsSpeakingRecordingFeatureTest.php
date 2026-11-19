<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Plugins\Modules\Phonetics\Models\PhoneticEntry;
use Plugins\Modules\Phonetics\Models\PhoneticSetting;
use Plugins\Modules\Phonetics\Models\PhoneticSpeakingRecording;
use Tests\TestCase;

final class PhoneticsSpeakingRecordingFeatureTest extends TestCase
{
    public function test_student_recordings_are_private_owner_scoped_and_teacher_reviewable(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'phonetics']);

        $student = $this->createUserWithRole('student');
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'PHON-REC-001',
            'title' => 'British and American Phonetics',
            'slug' => 'british-american-phonetics',
            'description' => 'Speaking practice',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $courseId,
            'branch_id' => null,
            'status' => 'active',
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
            'word' => 'voice-test',
            'category' => 'rhoticity',
            'uk_ipa' => '/vɔɪs/',
            'us_ipa' => '/vɔɪs/',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        DB::table('phonetic_learning_assignments')->insert([
            'lms_driver' => 'aec',
            'course_ref' => (string) $courseId,
            'course_category_ref' => null,
            'lesson_ref' => null,
            'batch_ref' => null,
            'word_id' => $entry->id,
            'audience' => 'both',
            'sort_order' => 1,
            'is_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $response = $this->actingAs($student)->postJson(
                '/courses/british-american-phonetics/phonetics-learning/recordings',
                [
                    'entry_id' => $entry->id,
                    'duration_seconds' => 8,
                    'recording' => UploadedFile::fake()->create('speech.webm', 128, 'audio/webm'),
                ]
            )->assertCreated()->assertJson(['saved' => true, 'status' => 'pending']);

            $recordingId = $response->json('recording_id');
            $recording = PhoneticSpeakingRecording::query()->findOrFail($recordingId);
            $privatePath = storage_path('app/private/phonetics-recordings/' . $recording->storage_path);

            $this->assertFileExists($privatePath);
            $this->assertFileDoesNotExist(public_path($recording->storage_path));

            $this->actingAs($student)
                ->get('/courses/british-american-phonetics/phonetics-learning/recordings/' . $recordingId . '/audio')
                ->assertOk();
            $this->actingAs($student)
                ->getJson('/courses/british-american-phonetics/phonetics-learning/recordings')
                ->assertOk()
                ->assertJsonPath(
                    'recordings.0.audio_url',
                    url('/courses/british-american-phonetics/phonetics-learning/recordings/' . $recordingId . '/audio')
                )
                ->assertJsonPath(
                    'recordings.0.delete_url',
                    url('/courses/british-american-phonetics/phonetics-learning/recordings/' . $recordingId)
                );

            // The second student is enrolled too, so this specifically verifies
            // recording ownership concealment rather than failing course access.
            $otherStudent = $this->createUserWithRole('student');
            DB::table('enrollments')->insert([
                'user_id' => $otherStudent->id,
                'course_id' => $courseId,
                'branch_id' => null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->actingAs($otherStudent)
                ->get('/courses/british-american-phonetics/phonetics-learning/recordings/' . $recordingId . '/audio')
                ->assertNotFound();

            $admin = $this->createUserWithRole('admin');
            $this->actingAs($admin)->patch('/admin/phonetics/learning/recordings/' . $recordingId . '/review', [
                'score' => 4,
                'feedback' => 'Good clarity. Practise the final consonant release.',
            ])->assertSessionHas('status');

            $this->assertDatabaseHas('phonetic_speaking_recordings', [
                'id' => $recordingId,
                'status' => 'reviewed',
                'score' => 4,
                'reviewer_user_id' => (string) $admin->getAuthIdentifier(),
            ]);
        } finally {
            File::deleteDirectory(storage_path('app/private/phonetics-recordings'));
        }
    }
}
