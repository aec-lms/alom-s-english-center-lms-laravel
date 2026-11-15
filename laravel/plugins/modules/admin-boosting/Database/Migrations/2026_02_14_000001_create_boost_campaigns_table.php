<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('boost_campaigns')) {
            Schema::create('boost_campaigns', function (Blueprint $table): void {
                $table->id();
                $table->string('campaign_name', 180);
                $table->string('entity_type', 40)->default('course');
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->string('placement', 60)->default('homepage_featured');
                $table->string('badge_text', 60)->nullable();
                $table->string('cta_text', 60)->default('Explore Offer');
                $table->string('destination_url', 255)->nullable();
                $table->unsignedTinyInteger('priority')->default(5);
                $table->string('status', 24)->default('active');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->unsignedBigInteger('impressions')->default(0);
                $table->unsignedBigInteger('clicks')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall per core policy
    }
};
