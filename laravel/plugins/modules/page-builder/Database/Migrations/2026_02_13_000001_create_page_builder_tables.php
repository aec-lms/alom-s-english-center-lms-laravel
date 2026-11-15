<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cms_pages')) {
            Schema::create('cms_pages', function (Blueprint $table): void {
                $table->id();
                $table->string('slug', 160)->unique();
                $table->string('title', 180);
                $table->string('template', 40)->default('standard');
                $table->string('banner_image', 255)->nullable();
                $table->json('blocks_json');
                $table->string('seo_title', 180)->nullable();
                $table->string('seo_description', 320)->nullable();
                $table->boolean('is_published')->default(false);
                $table->timestamps();
            });
        } elseif (!Schema::hasColumn('cms_pages', 'template')) {
            Schema::table('cms_pages', function (Blueprint $table): void {
                $table->string('template', 40)->default('standard')->after('title');
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
