<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cms_posts') && Schema::hasTable('users')) {
            Schema::table('cms_posts', function (Blueprint $table): void {
                if (!Schema::hasColumn('cms_posts', 'updated_by')) {
                    $table->unsignedBigInteger('updated_by')->nullable()->index();
                }
                if (!Schema::hasColumn('cms_posts', 'assigned_editor_id')) {
                    $table->unsignedBigInteger('assigned_editor_id')->nullable()->index();
                }
                if (!Schema::hasColumn('cms_posts', 'assigned_reviewer_id')) {
                    $table->unsignedBigInteger('assigned_reviewer_id')->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('cms_posts') && Schema::hasTable('users') && !Schema::hasTable('cms_post_authors')) {
            Schema::create('cms_post_authors', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('post_id')->constrained('cms_posts')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('contributor_type', 24)->default('co_author');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['post_id', 'user_id'], 'cms_post_authors_post_user_unique');
                $table->index(['user_id', 'contributor_type'], 'cms_post_authors_user_type_idx');
            });
        }

        if (!Schema::hasTable('roles') || !Schema::hasTable('permissions') || !Schema::hasTable('permission_role')) {
            return;
        }

        $permissionNames = [
            'cms.posts.view' => 'View assigned CMS posts',
            'cms.posts.create' => 'Create CMS posts',
            'cms.posts.edit_own' => 'Edit own or assigned CMS posts',
            'cms.posts.edit_all' => 'Edit all CMS posts',
            'cms.posts.submit' => 'Submit CMS posts for editorial review',
            'cms.posts.review' => 'Review and request changes to CMS posts',
            'cms.posts.publish' => 'Publish approved CMS posts',
            'cms.authors.manage' => 'Manage CMS contributor access',
            'cms.authors.assign' => 'Assign CMS authors, editors and reviewers',
        ];

        $permissionIds = [];
        foreach ($permissionNames as $slug => $name) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name, 'module' => 'cms', 'updated_at' => now(), 'created_at' => now()]
            );
            $permissionIds[$slug] = (int) DB::table('permissions')->where('slug', $slug)->value('id');
        }

        $roleDefinitions = [
            'cms_contributor' => [
                'name' => 'CMS Contributor',
                'description' => 'Creates and edits own drafts and submits them for review.',
                'permissions' => ['cms.posts.view', 'cms.posts.create', 'cms.posts.edit_own', 'cms.posts.submit'],
            ],
            'cms_editor' => [
                'name' => 'CMS Editor',
                'description' => 'Coordinates editorial work, edits all CMS posts and publishes approved content.',
                'permissions' => ['cms.posts.view', 'cms.posts.create', 'cms.posts.edit_own', 'cms.posts.edit_all', 'cms.posts.submit', 'cms.posts.review', 'cms.posts.publish', 'cms.authors.assign'],
            ],
            'cms_reviewer' => [
                'name' => 'CMS Reviewer',
                'description' => 'Reviews submitted posts and requests changes or approval.',
                'permissions' => ['cms.posts.view', 'cms.posts.review'],
            ],
            'cms_author_manager' => [
                'name' => 'CMS Contributor Manager',
                'description' => 'Manages CMS contributor access and post assignments.',
                'permissions' => ['cms.posts.view', 'cms.authors.manage', 'cms.authors.assign'],
            ],
        ];

        foreach ($roleDefinitions as $slug => $definition) {
            DB::table('roles')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $definition['name'], 'description' => $definition['description'], 'is_system' => true, 'updated_at' => now(), 'created_at' => now()]
            );
            $roleId = (int) DB::table('roles')->where('slug', $slug)->value('id');
            foreach ($definition['permissions'] as $permissionSlug) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionIds[$permissionSlug],
                ]);
            }
        }
    }

    public function down(): void
    {
        // Keep contributor roles, permissions and authorship history to avoid orphaning audit records.
    }
};
