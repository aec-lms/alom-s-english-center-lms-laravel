<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table): void {
                if (!Schema::hasColumn('courses', 'cms_post_id')) {
                    $table->unsignedBigInteger('cms_post_id')->nullable()->after('category_id')->index();
                }
                if (!Schema::hasColumn('courses', 'cms_category_id')) {
                    $table->unsignedBigInteger('cms_category_id')->nullable()->after('cms_post_id')->index();
                }
                if (!Schema::hasColumn('courses', 'instructor_name')) {
                    $table->string('instructor_name', 120)->nullable()->after('category');
                }
                if (!Schema::hasColumn('courses', 'syllabus_json')) {
                    $table->longText('syllabus_json')->nullable()->after('description');
                }
                if (!Schema::hasColumn('courses', 'pdf_path')) {
                    $table->string('pdf_path', 255)->nullable()->after('thumbnail');
                }
                if (!Schema::hasColumn('courses', 'pdf_thumbnail')) {
                    $table->string('pdf_thumbnail', 255)->nullable()->after('pdf_path');
                }
                if (!Schema::hasColumn('courses', 'seo_title')) {
                    $table->string('seo_title', 180)->nullable()->after('pdf_thumbnail');
                }
                if (!Schema::hasColumn('courses', 'seo_description')) {
                    $table->string('seo_description', 320)->nullable()->after('seo_title');
                }
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
