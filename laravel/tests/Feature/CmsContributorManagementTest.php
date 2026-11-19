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
        $this->grantCmsRole($manager, 'cms_reviewer');
        $post = $this->createDraft($author, 'Managed Assignment Draft');

        $this->actingAs($manager)->put('/admin/cms/contributors/' . $author->id, ['roles' => ['cms_contributor']])
            ->assertRedirect();
        $this->assertTrue($author->fresh()->hasRole('student'), 'CMS role updates must preserve the existing LMS role.');

        $this->actingAs($manager)->put('/admin/cms/editorial/posts/' . $post->id . '/assignments', [
            'author_user_id' => $author->id,
            'assigned_editor_id' => $editor->id,
            'assigned_reviewer_id' => $manager->id,
            'co_authors' => [],
        ])->assertStatus(422);

        $this->actingAs($manager)->put('/admin/cms/editorial/posts/' . $post->id . '/assignments', [
            'author_user_id' => $author->id,
            'assigned_editor_id' => $editor->id,
            'assigned_reviewer_id' => $reviewer->id,
            'co_authors' => [],
        ])->assertSessionHasNoErrors();

        $this->actingAs($manager)->put('/admin/cms/contributors/' . $reviewer->id, ['roles' => []])
            ->assertStatus(422);

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

    public function test_co_authors_and_assigned_editors_can_edit_only_their_assigned_unpublished_posts(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);

        $author = $this->createUserWithRole('student');
        $coAuthor = $this->createUserWithRole('student');
        $editor = $this->createUserWithRole('teacher');
        $this->grantCmsRole($author, 'cms_contributor');
        $this->grantCmsRole($coAuthor, 'cms_contributor');
        $this->grantCmsRole($editor, 'cms_editor');
        $post = $this->createDraft($author, 'Collaborative Draft');

        $this->actingAs($editor)->put('/admin/cms/editorial/posts/' . $post->id . '/assignments', [
            'author_user_id' => $author->id,
            'assigned_editor_id' => $editor->id,
            'co_authors' => [$coAuthor->id],
        ])->assertSessionHasNoErrors();

        $this->actingAs($coAuthor)->put('/admin/cms/editorial/posts/' . $post->id, [
            'title' => 'Collaborative Draft Updated',
            'slug' => $post->slug,
            'content' => 'Co-author contribution saved.',
        ])->assertSessionHasNoErrors();

        $this->actingAs($editor)->put('/admin/cms/editorial/posts/' . $post->id, [
            'title' => 'Collaborative Draft Finalized',
            'slug' => $post->slug,
            'content' => 'Assigned editor final review saved.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cms_posts', [
            'id' => $post->id,
            'title' => 'Collaborative Draft Finalized',
            'content' => 'Assigned editor final review saved.',
            'editorial_status' => 'draft',
            'is_published' => false,
        ]);
    }



    public function test_cms_editor_cannot_self_approve_its_own_submission(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $editor = $this->createUserWithRole('teacher');
        $this->grantCmsRole($editor, 'cms_editor');
        $post = $this->createDraft($editor, 'Editor Self Review Draft');

        $this->actingAs($editor)->post('/admin/cms/editorial/posts/' . $post->id . '/submit-review')
            ->assertSessionHasNoErrors();
        $this->actingAs($editor)->post('/admin/cms/editorial/posts/' . $post->id . '/approve')
            ->assertForbidden();

        $this->assertDatabaseHas('cms_posts', [
            'id' => $post->id,
            'editorial_status' => 'in_review',
            'submitted_by' => $editor->id,
            'reviewed_by' => null,
            'is_published' => false,
        ]);
    }



    public function test_manager_access_can_be_removed_only_when_another_active_manager_remains(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);

        $firstManager = $this->createUserWithRole('student');
        $secondManager = $this->createUserWithRole('student');
        $this->grantCmsRole($firstManager, 'cms_author_manager');
        $this->grantCmsRole($secondManager, 'cms_author_manager');

        $this->actingAs($firstManager)->put('/admin/cms/contributors/' . $firstManager->id, ['roles' => []])
            ->assertRedirect();
        $this->assertFalse($firstManager->fresh()->hasRole('cms_author_manager'));
        $this->assertTrue($secondManager->fresh()->hasRole('cms_author_manager'));

        $this->actingAs($secondManager)->put('/admin/cms/contributors/' . $secondManager->id, ['roles' => []])
            ->assertStatus(422);
        $this->assertTrue($secondManager->fresh()->hasRole('cms_author_manager'));
    }

    public function test_last_active_contributor_manager_cannot_remove_their_own_management_access(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $manager = $this->createUserWithRole('student');
        $this->grantCmsRole($manager, 'cms_author_manager');

        $this->actingAs($manager)->put('/admin/cms/contributors/' . $manager->id, ['roles' => []])
            ->assertStatus(422);

        $this->assertTrue($manager->fresh()->hasRole('cms_author_manager'));
    }



    public function test_reviewer_access_cannot_be_revoked_while_a_trashed_post_retains_the_assignment(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $author = $this->createUserWithRole('student');
        $reviewer = $this->createUserWithRole('teacher');
        $manager = $this->createUserWithRole('student');
        $this->grantCmsRole($author, 'cms_contributor');
        $this->grantCmsRole($reviewer, 'cms_reviewer');
        $this->grantCmsRole($manager, 'cms_author_manager');
        $post = $this->createDraft($author, 'Trashed Assigned Review');
        $post->forceFill(['assigned_reviewer_id' => $reviewer->id])->save();
        $post->delete();

        $this->actingAs($manager)->put('/admin/cms/contributors/' . $reviewer->id, ['roles' => []])
            ->assertStatus(422);

        $this->assertTrue($reviewer->fresh()->hasRole('cms_reviewer'));
        $this->assertSoftDeleted('cms_posts', ['id' => $post->id]);
    }



    public function test_reviewer_assignment_rejects_existing_coauthor_and_editor_when_fields_are_omitted(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);

        $author = $this->createUserWithRole('student');
        $coAuthor = $this->createUserWithRole('teacher');
        $editor = $this->createUserWithRole('teacher');
        $manager = $this->createUserWithRole('student');
        $this->grantCmsRole($author, 'cms_contributor');
        $this->grantCmsRole($coAuthor, 'cms_contributor');
        $this->grantCmsRole($editor, 'cms_editor');
        $this->grantCmsRole($manager, 'cms_author_manager');
        $post = $this->createDraft($author, 'Existing Assignment Independence');

        $post->forceFill(['assigned_editor_id' => $editor->id])->save();
        $post->contributors()->sync([$coAuthor->id => ['contributor_type' => 'co_author', 'sort_order' => 1]]);

        $this->actingAs($manager)->put('/admin/cms/editorial/posts/' . $post->id . '/assignments', [
            'assigned_reviewer_id' => $coAuthor->id,
        ])->assertStatus(422);

        $this->actingAs($manager)->put('/admin/cms/editorial/posts/' . $post->id . '/assignments', [
            'assigned_reviewer_id' => $editor->id,
        ])->assertStatus(422);

        $this->assertDatabaseHas('cms_posts', [
            'id' => $post->id,
            'assigned_editor_id' => $editor->id,
            'assigned_reviewer_id' => null,
        ]);
    }



    public function test_partial_assignment_update_preserves_omitted_editor_and_coauthors(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);

        $author = $this->createUserWithRole('student');
        $coAuthor = $this->createUserWithRole('teacher');
        $editor = $this->createUserWithRole('teacher');
        $reviewer = $this->createUserWithRole('teacher');
        $manager = $this->createUserWithRole('student');
        $this->grantCmsRole($author, 'cms_contributor');
        $this->grantCmsRole($coAuthor, 'cms_contributor');
        $this->grantCmsRole($editor, 'cms_editor');
        $this->grantCmsRole($reviewer, 'cms_reviewer');
        $this->grantCmsRole($manager, 'cms_author_manager');
        $post = $this->createDraft($author, 'Partial Assignment Preservation');

        $post->forceFill(['assigned_editor_id' => $editor->id])->save();
        $post->contributors()->sync([$coAuthor->id => ['contributor_type' => 'co_author', 'sort_order' => 1]]);

        $this->actingAs($manager)->put('/admin/cms/editorial/posts/' . $post->id . '/assignments', [
            'assigned_reviewer_id' => $reviewer->id,
        ])->assertRedirect();

        $post->refresh();
        $this->assertSame((int) $editor->id, (int) $post->assigned_editor_id);
        $this->assertSame((int) $reviewer->id, (int) $post->assigned_reviewer_id);
        $this->assertSame([$coAuthor->id], $post->contributors()->pluck('users.id')->map(static fn ($id): int => (int) $id)->all());
    }


}
