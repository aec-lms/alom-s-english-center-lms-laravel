<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\CmsWebsite\Models\CmsPost;
use Tests\TestCase;

final class CmsContributorManagementTest extends TestCase
{
    private function grantCmsRole(User $user, string $slug): void
    {
        $roleId = DB::table('roles')->where('slug', $slug)->value('id');
        $this->assertNotNull($roleId, "Expected CMS role {$slug} to be installed.");
        $user->roles()->syncWithoutDetaching([(int) $roleId]);
    }

    private function createDraft(User $author, string $title = 'Contributor Draft'): CmsPost
    {
        $this->actingAs($author)->post('/admin/cms/editorial/posts', [
            'title' => $title,
            'content' => 'Editorial draft content',
        ])->assertSessionHasNoErrors();

        return CmsPost::query()->where('title', $title)->firstOrFail();
    }

    public function test_cms_roles_are_seeded_and_contributors_only_see_their_own_editorial_queue(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);

        $this->assertDatabaseHas('roles', ['slug' => 'cms_contributor']);
        $this->assertDatabaseHas('roles', ['slug' => 'cms_editor']);
        $this->assertDatabaseHas('roles', ['slug' => 'cms_reviewer']);
        $this->assertDatabaseHas('permissions', ['slug' => 'cms.posts.edit_own']);

        $author = $this->createUserWithRole('student');
        $otherAuthor = $this->createUserWithRole('student');
        $this->grantCmsRole($author, 'cms_contributor');
        $this->grantCmsRole($otherAuthor, 'cms_contributor');

        $post = $this->createDraft($author, 'Private Contributor Draft');

        $this->actingAs($author)->get('/admin/cms/editorial')
            ->assertOk()->assertSee('Private Contributor Draft');
        $this->actingAs($otherAuthor)->get('/admin/cms/editorial')
            ->assertOk()->assertDontSee('Private Contributor Draft');

        $this->assertDatabaseHas('cms_posts', [
            'id' => $post->id,
            'created_by' => $author->id,
            'author_user_id' => $author->id,
            'is_published' => false,
            'editorial_status' => 'draft',
        ]);
    }

    public function test_contributor_cannot_edit_another_authors_post_or_self_approve(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);

        $author = $this->createUserWithRole('student');
        $other = $this->createUserWithRole('student');
        $this->grantCmsRole($author, 'cms_contributor');
        $this->grantCmsRole($other, 'cms_contributor');
        $post = $this->createDraft($author, 'Author Owned Draft');

        $this->actingAs($other)->put('/admin/cms/editorial/posts/' . $post->id, [
            'title' => 'Unauthorized Edit',
            'slug' => $post->slug,
            'content' => 'Unauthorized content',
        ])->assertForbidden();

        $this->actingAs($author)->post('/admin/cms/editorial/posts/' . $post->id . '/submit-review')
            ->assertSessionHasNoErrors();
        $this->actingAs($author)->post('/admin/cms/editorial/posts/' . $post->id . '/approve')
            ->assertForbidden();

        $this->assertDatabaseHas('cms_posts', [
            'id' => $post->id,
            'title' => 'Author Owned Draft',
            'editorial_status' => 'in_review',
            'reviewed_by' => null,
        ]);
    }

    public function test_only_the_assigned_independent_reviewer_can_approve_a_submission(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);

        $author = $this->createUserWithRole('student');
        $reviewer = $this->createUserWithRole('teacher');
        $otherReviewer = $this->createUserWithRole('teacher');
        $this->grantCmsRole($author, 'cms_contributor');
        $this->grantCmsRole($reviewer, 'cms_reviewer');
        $this->grantCmsRole($otherReviewer, 'cms_reviewer');
        $post = $this->createDraft($author, 'Assigned Review Draft');
        $post->forceFill(['assigned_reviewer_id' => $reviewer->id])->save();

        $this->actingAs($author)->post('/admin/cms/editorial/posts/' . $post->id . '/submit-review')
            ->assertSessionHasNoErrors();
        $this->actingAs($otherReviewer)->post('/admin/cms/editorial/posts/' . $post->id . '/approve')
            ->assertForbidden();
        $this->actingAs($reviewer)->post('/admin/cms/editorial/posts/' . $post->id . '/approve')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cms_posts', [
            'id' => $post->id,
            'editorial_status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'is_published' => false,
        ]);
    }

    public function test_author_manager_can_assign_contributors_but_cannot_edit_post_content_or_revoke_active_author_access(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);

        $author = $this->createUserWithRole('student');
        $editor = $this->createUserWithRole('teacher');
        $reviewer = $this->createUserWithRole('teacher');
        $manager = $this->createUserWithRole('student');
        $this->grantCmsRole($author, 'cms_contributor');
        $this->grantCmsRole($editor, 'cms_editor');
        $this->grantCmsRole($reviewer, 'cms_reviewer');
        $this->grantCmsRole($manager, 'cms_author_manager');
        $post = $this->createDraft($author, 'Managed Assignment Draft');

        $this->actingAs($manager)->put('/admin/cms/editorial/posts/' . $post->id . '/assignments', [
            'author_user_id' => $author->id,
            'assigned_editor_id' => $editor->id,
            'assigned_reviewer_id' => $reviewer->id,
            'co_authors' => [],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cms_posts', [
            'id' => $post->id,
            'assigned_editor_id' => $editor->id,
            'assigned_reviewer_id' => $reviewer->id,
        ]);

        $this->actingAs($manager)->put('/admin/cms/editorial/posts/' . $post->id, [
            'title' => 'Manager Must Not Edit Content',
            'slug' => $post->slug,
            'content' => 'Unauthorized content edit',
        ])->assertForbidden();

        $this->actingAs($manager)->put('/admin/cms/contributors/' . $author->id, ['roles' => []])
            ->assertStatus(422);

        $this->assertDatabaseHas('cms_posts', [
            'id' => $post->id,
            'title' => 'Managed Assignment Draft',
            'assigned_editor_id' => $editor->id,
        ]);
    }
}
