<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cms_categories')) {
            Schema::create('cms_categories', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 150)->unique();
                $table->string('description', 320)->nullable();
                $table->string('color', 32)->default('sky');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('cms_posts')) {
            Schema::table('cms_posts', function (Blueprint $table): void {
                if (!Schema::hasColumn('cms_posts', 'category_id')) {
                    $table->unsignedBigInteger('category_id')->nullable()->after('title')->index();
                }
                if (!Schema::hasColumn('cms_posts', 'pdf_path')) {
                    $table->string('pdf_path', 255)->nullable()->after('thumbnail_path');
                }
                if (!Schema::hasColumn('cms_posts', 'pdf_thumbnail')) {
                    $table->string('pdf_thumbnail', 255)->nullable()->after('pdf_path');
                }
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
