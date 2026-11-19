<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cms_post_revisions')) {
            Schema::create('cms_post_revisions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('post_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('title', 200);
                $table->string('slug', 180);
                $table->string('category', 100)->default('General');
                $table->unsignedBigInteger('category_id')->nullable();
                $table->string('author', 120)->nullable();
                $table->text('excerpt')->nullable();
                $table->longText('content');
                $table->string('thumbnail_path', 255)->nullable();
                $table->string('pdf_path', 255)->nullable();
                $table->string('pdf_thumbnail', 255)->nullable();
                $table->string('seo_title', 180)->nullable();
                $table->string('seo_description', 320)->nullable();
                $table->boolean('is_published')->default(false);
                $table->timestamp('created_at')->useCurrent();
                $table->index(['post_id', 'created_at']);
            });
        }
    }
    public function down(): void { Schema::dropIfExists('cms_post_revisions'); }
};
