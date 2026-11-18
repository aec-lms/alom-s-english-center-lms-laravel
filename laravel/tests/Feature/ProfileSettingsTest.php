<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    public function test_user_can_update_public_profile_fields_and_social_links_without_changing_access_identity(): void
    {
        $this->markSystemInstalled();
        $user = $this->createUserWithRole('student', 17, 'profile-student@aec.test');

        $this->actingAs($user)->put(route('profile.settings.update'), [
            'username' => 'My_English_ID',
            'display_name' => 'My Learning Name',
            'headline' => 'English learner',
            'bio' => 'Practising English every day.',
            'location' => 'Dhaka, Bangladesh',
            'website' => 'https://example.com',
            'profile_is_public' => '1',
            'social_links' => [
                'facebook' => 'https://facebook.com/example',
                'youtube' => 'https://youtube.com/@example',
            ],
        ])->assertRedirect(route('profile.settings.edit'));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'branch_id' => 17,
            'username' => 'my_english_id',
            'display_name' => 'My Learning Name',
            'profile_is_public' => true,
        ]);
        $this->assertEquals([
            'facebook' => 'https://facebook.com/example',
            'youtube' => 'https://youtube.com/@example',
        ], $user->fresh()->social_links);
    }

    public function test_user_can_upload_a_valid_avatar_and_cover_image(): void
    {
        $this->markSystemInstalled();
        Storage::fake('local');
        $user = $this->createUserWithRole('teacher', 4, 'profile-teacher@aec.test');

        $this->actingAs($user)->put(route('profile.settings.update'), [
            'avatar' => UploadedFile::fake()->image('avatar.webp', 300, 300),
            'cover' => UploadedFile::fake()->image('cover.jpg', 1200, 400),
            'profile_is_public' => '1',
        ])->assertRedirect(route('profile.settings.edit'));

        $fresh = $user->fresh();
        $this->assertNotEmpty($fresh->avatar_path);
        $this->assertNotEmpty($fresh->cover_path);
        Storage::disk('local')->assertExists($fresh->avatar_path);
        Storage::disk('local')->assertExists($fresh->cover_path);
    }

    public function test_duplicate_usernames_are_rejected_and_role_branch_and_email_cannot_be_changed(): void
    {
        $this->markSystemInstalled();
        $first = $this->createUserWithRole('student', 2, 'profile-first@aec.test');
        $second = $this->createUserWithRole('guardian', 9, 'profile-second@aec.test');
        $first->forceFill(['username' => 'taken_name'])->save();

        $this->actingAs($second)->from(route('profile.settings.edit'))
            ->put(route('profile.settings.update'), [
                'username' => 'TAKEN_NAME',
                'display_name' => 'Guardian Public Name',
                'branch_id' => 2,
                'email' => 'changed@aec.test',
                'role' => 'admin',
            ])->assertSessionHasErrors('username');

        $fresh = $second->fresh();
        $this->assertSame('profile-second@aec.test', $fresh->email);
        $this->assertSame(9, (int) $fresh->branch_id);
        $this->assertTrue($fresh->hasRole('guardian'));
        $this->assertFalse($fresh->hasRole('admin'));
    }

    public function test_profile_images_are_private_until_the_profile_is_public(): void
    {
        $this->markSystemInstalled();
        Storage::fake('local');
        $user = $this->createUserWithRole('student', 5, 'profile-media@aec.test');
        $path = 'users/avatars/'.$user->id.'/avatar.jpg';
        Storage::disk('local')->put($path, 'fake-image');
        $user->forceFill(['username' => 'media_learner', 'avatar_path' => $path, 'profile_is_public' => false])->save();

        $this->get(route('profile.media', ['username' => 'media_learner', 'type' => 'avatar']))->assertNotFound();
        $this->actingAs($user)->get(route('profile.settings.media', ['type' => 'avatar']))->assertOk();

        $user->forceFill(['profile_is_public' => true])->save();
        $this->get(route('profile.media', ['username' => 'media_learner', 'type' => 'avatar']))->assertOk();
    }

    public function test_public_profile_is_available_only_when_user_enables_visibility(): void
    {
        $this->markSystemInstalled();
        $user = $this->createUserWithRole('student', null, 'profile-public@aec.test');
        $user->forceFill([
            'username' => 'public_learner',
            'display_name' => 'Public Learner',
            'profile_is_public' => true,
            'social_links' => ['youtube' => 'https://youtube.com/@learner'],
        ])->save();

        $this->get(route('profile.public', ['username' => 'public_learner']))
            ->assertOk()
            ->assertSee('Public Learner')
            ->assertSee('youtube.com/@learner');

        $user->forceFill(['profile_is_public' => false])->save();
        $this->get(route('profile.public', ['username' => 'public_learner']))->assertNotFound();
    }

    public function test_profile_activity_history_is_private_and_shows_only_the_signed_in_users_events(): void
    {
        $this->markSystemInstalled();
        $owner = $this->createUserWithRole('student', 6, 'profile-activity-owner@aec.test');
        $other = $this->createUserWithRole('student', 7, 'profile-activity-other@aec.test');

        \App\Models\AuditLog::query()->create([
            'user_id' => $owner->id, 'action' => 'profile.updated', 'entity_type' => 'user',
            'entity_id' => (string) $owner->id, 'metadata' => ['changed_fields' => ['bio', 'avatar']],
        ]);
        \App\Models\AuditLog::query()->create([
            'user_id' => $other->id, 'action' => 'profile.updated', 'entity_type' => 'user',
            'entity_id' => (string) $other->id, 'metadata' => ['changed_fields' => ['headline']],
        ]);

        $this->get(route('profile.settings.activity'))->assertRedirect(route('login'));
        $this->actingAs($owner)->get(route('profile.settings.activity'))
            ->assertOk()
            ->assertSee('Profile activity')
            ->assertSee('Bio')
            ->assertDontSee('Headline');
    }

    public function test_profile_updates_write_only_changed_field_names_to_the_audit_log(): void
    {
        $this->markSystemInstalled();
        $user = $this->createUserWithRole('student', 6, 'profile-audit@aec.test');

        $this->actingAs($user)->put(route('profile.settings.update'), [
            'display_name' => 'Secret Display Value',
            'bio' => 'Private biography value',
            'profile_is_public' => '1',
        ])->assertRedirect(route('profile.settings.edit'));

        $log = \App\Models\AuditLog::query()
            ->where('user_id', $user->id)->where('action', 'profile.updated')->firstOrFail();
        $this->assertContains('display_name', $log->metadata['changed_fields']);
        $this->assertContains('bio', $log->metadata['changed_fields']);
        $this->assertContains('profile_is_public', $log->metadata['changed_fields']);
        $this->assertStringNotContainsString('Secret Display Value', json_encode($log->metadata));
        $this->assertStringNotContainsString('Private biography value', json_encode($log->metadata));
    }

    public function test_password_change_requires_current_password_and_revokes_other_device_sessions(): void
    {
        $this->markSystemInstalled();
        $user = $this->createUserWithRole('student', 6, 'profile-password@aec.test');
        $currentHash = hash('sha256', 'current-session-id');
        $otherHash = hash('sha256', 'other-session-id');
        \App\Models\DeviceSession::query()->create([
            'user_id' => $user->id, 'session_hash' => $currentHash, 'device_name' => 'Current',
            'logged_in_at' => now(), 'last_seen_at' => now(),
        ]);
        $other = \App\Models\DeviceSession::query()->create([
            'user_id' => $user->id, 'session_hash' => $otherHash, 'device_name' => 'Other',
            'logged_in_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->actingAs($user)->withSession(['device_session_hash' => $currentHash])
            ->put(route('profile.settings.update'), [
                'current_password' => 'incorrect-current-password',
                'new_password' => 'A_New_Strong_Password_2026!',
                'new_password_confirmation' => 'A_New_Strong_Password_2026!',
            ])->assertSessionHasErrors('current_password');

        $this->actingAs($user)->withSession(['device_session_hash' => $currentHash])
            ->put(route('profile.settings.update'), [
                'current_password' => 'StrongPassword!2026',
                'new_password' => 'A_New_Strong_Password_2026!',
                'new_password_confirmation' => 'A_New_Strong_Password_2026!',
            ])->assertRedirect(route('profile.settings.edit'));

        $this->assertNotNull($other->fresh()->revoked_at);
        $this->assertDatabaseHas('device_sessions', ['session_hash' => $currentHash, 'revoked_at' => null]);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('A_New_Strong_Password_2026!', $user->fresh()->password));
        $log = \App\Models\AuditLog::query()->where('user_id', $user->id)->where('action', 'security.password_changed')->firstOrFail();
        $this->assertStringNotContainsString('A_New_Strong_Password_2026!', json_encode($log->metadata));
    }

    public function test_social_links_reject_non_http_schemes(): void
    {
        $this->markSystemInstalled();
        $user = $this->createUserWithRole('student', 3, 'profile-links@aec.test');

        $this->actingAs($user)->from(route('profile.settings.edit'))
            ->put(route('profile.settings.update'), [
                'social_links' => ['facebook' => 'javascript:alert(1)'],
                'profile_is_public' => '1',
            ])->assertSessionHasErrors('social_links.facebook');
    }

    public function test_profile_settings_are_authenticated_and_uploads_are_validated(): void
    {
        $this->markSystemInstalled();
        $this->get(route('profile.settings.edit'))->assertRedirect(route('login'));

        $user = $this->createUserWithRole('student');
        $this->actingAs($user)->put(route('profile.settings.update'), [
            'avatar' => UploadedFile::fake()->create('not-an-image.pdf', 200, 'application/pdf'),
        ])->assertSessionHasErrors('avatar');
    }
}
