<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cms_posts') && !Schema::hasColumn('cms_posts', 'publish_at')) {
            Schema::table('cms_posts', function (Blueprint $table): void {
                $table->timestamp('publish_at')->nullable()->index();
            });
        }
        if (Schema::hasTable('cms_post_revisions') && !Schema::hasColumn('cms_post_revisions', 'publish_at')) {
            Schema::table('cms_post_revisions', function (Blueprint $table): void {
                $table->timestamp('publish_at')->nullable();
            });
        }
    }
    public function down(): void
    {
        // Retain revision history and scheduled publication metadata during rollback.
    }
};
