<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cms_carousels')) {
            Schema::create('cms_carousels', function (Blueprint $table): void {
                $table->id();
                $table->string('carousel_group', 80)->default('featured_carousel'); // featured_carousel, testimonial_left, testimonial_right, course_showcase, partner_logos
                $table->string('title', 180);
                $table->string('subtitle', 500)->nullable();
                $table->string('image_path', 255)->nullable();
                $table->string('cta_url', 255)->nullable();
                $table->unsignedTinyInteger('rating')->default(5);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
