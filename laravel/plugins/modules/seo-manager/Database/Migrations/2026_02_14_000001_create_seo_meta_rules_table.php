<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('seo_meta_rules')) {
            Schema::create('seo_meta_rules', function (Blueprint $table): void {
                $table->id();
                $table->string('route_path', 180)->unique();
                $table->string('meta_title', 200);
                $table->string('meta_description', 340)->nullable();
                $table->string('meta_keywords', 340)->nullable();
                $table->string('canonical_url', 255)->nullable();
                $table->string('og_image', 255)->nullable();
                $table->string('robots_directive', 60)->default('index,follow');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall per core policy
    }
};
