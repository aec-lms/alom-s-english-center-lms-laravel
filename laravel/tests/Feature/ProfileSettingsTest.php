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
        $this->assertSame([
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
