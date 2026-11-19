<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cms_posts')) return;

        Schema::table('cms_posts', function (Blueprint $table): void {
            if (!Schema::hasColumn('cms_posts', 'editorial_status')) $table->string('editorial_status', 24)->default('draft')->index();
            if (!Schema::hasColumn('cms_posts', 'created_by')) $table->unsignedBigInteger('created_by')->nullable()->index();
            if (!Schema::hasColumn('cms_posts', 'author_user_id')) $table->unsignedBigInteger('author_user_id')->nullable()->index();
            if (!Schema::hasColumn('cms_posts', 'submitted_at')) $table->timestamp('submitted_at')->nullable();
            if (!Schema::hasColumn('cms_posts', 'submitted_by')) $table->unsignedBigInteger('submitted_by')->nullable()->index();
            if (!Schema::hasColumn('cms_posts', 'reviewed_at')) $table->timestamp('reviewed_at')->nullable();
            if (!Schema::hasColumn('cms_posts', 'reviewed_by')) $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            if (!Schema::hasColumn('cms_posts', 'review_note')) $table->text('review_note')->nullable();
        });

        DB::table('cms_posts')->where('is_published', true)->where('editorial_status', 'draft')
            ->update(['editorial_status' => 'published']);
    }

    public function down(): void
    {
        // Preserve editorial history and approvals during rollback; a destructive
        // schema rollback would erase reviewer decisions and authorship metadata.
    }
};
