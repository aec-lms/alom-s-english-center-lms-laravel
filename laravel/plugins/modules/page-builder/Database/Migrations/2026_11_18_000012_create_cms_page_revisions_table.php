<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cms_pages') && !Schema::hasTable('cms_page_revisions')) {
            Schema::create('cms_page_revisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('builder_page_id')->constrained('cms_pages')->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->json('snapshot');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['builder_page_id', 'version'], 'cms_page_revision_version_unique');
                $table->index(['builder_page_id', 'created_at'], 'cms_page_revision_history_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cms_page_revisions') && !DB::table('cms_page_revisions')->exists()) {
            Schema::drop('cms_page_revisions');
        }
    }
};
