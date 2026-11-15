<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('marketing_campaigns')) {
            Schema::create('marketing_campaigns', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 160);
                $table->string('channel', 60)->default('Facebook & Search');
                $table->string('target_course', 160)->nullable();
                $table->decimal('budget_bdt', 12, 2)->default(0);
                $table->string('utm_code', 80)->nullable();
                $table->string('status', 32)->default('active');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('promo_coupons')) {
            Schema::create('promo_coupons', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 40)->unique();
                $table->unsignedInteger('discount_percent')->default(10);
                $table->unsignedInteger('max_uses')->default(100);
                $table->unsignedInteger('used_count')->default(0);
                $table->date('expires_at')->nullable();
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
