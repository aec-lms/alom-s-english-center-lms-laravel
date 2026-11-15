<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cms_sliders')) {
            Schema::create('cms_sliders', function (Blueprint $table): void {
                $table->id();
                $table->string('key_name', 80)->default('hero');
                $table->string('title', 180);
                $table->string('subtitle', 255)->nullable();
                $table->string('image_path', 255)->nullable();
                $table->string('cta_url', 255)->nullable();
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
