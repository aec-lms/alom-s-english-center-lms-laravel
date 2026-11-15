<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('visitor_popups')) {
            Schema::create('visitor_popups', function (Blueprint $table): void {
                $table->id();
                $table->string('title', 180);
                $table->string('badge_text', 60)->nullable();
                $table->text('description')->nullable();
                $table->string('image_path', 255)->nullable();
                $table->string('cta_text', 80)->default('Claim Offer');
                $table->string('cta_url', 255)->default('/courses');
                $table->unsignedSmallInteger('delay_seconds')->default(3);
                $table->string('frequency', 32)->default('once_per_session');
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('views_count')->default(0);
                $table->unsignedBigInteger('clicks_count')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall per core policy
    }
};
