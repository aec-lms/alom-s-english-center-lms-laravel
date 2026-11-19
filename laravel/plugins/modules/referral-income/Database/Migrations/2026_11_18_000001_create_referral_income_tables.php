<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('referral_income_rules')) {
            Schema::create('referral_income_rules', function (Blueprint $table): void {
                $table->id();
                $table->string('referrer_type', 20)->unique();
                $table->decimal('commission_percent', 5, 2)->default(0);
                $table->decimal('minimum_payout', 12, 2)->default(500);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            DB::table('referral_income_rules')->insert([
                ['referrer_type' => 'student', 'commission_percent' => 5, 'minimum_payout' => 500, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['referrer_type' => 'teacher', 'commission_percent' => 10, 'minimum_payout' => 500, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        if (!Schema::hasTable('referral_codes')) {
            Schema::create('referral_codes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('owner_type', 20);
                $table->string('code', 32)->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['owner_user_id', 'owner_type']);
            });
        }

        if (!Schema::hasTable('referral_attributions')) {
            Schema::create('referral_attributions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete()->unique();
                $table->foreignId('referral_code_id')->constrained('referral_codes')->restrictOnDelete();
                $table->string('referrer_type', 20);
                $table->string('status', 20)->default('active');
                $table->timestamp('attributed_at');
                $table->timestamps();
                $table->index(['referrer_user_id', 'status']);
            });
        }

        if (!Schema::hasTable('referral_commissions')) {
            Schema::create('referral_commissions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('attribution_id')->constrained('referral_attributions')->restrictOnDelete();
                $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('source_type', 40);
                $table->string('source_id', 80);
                $table->decimal('eligible_amount', 12, 2);
                $table->decimal('commission_percent', 5, 2);
                $table->decimal('commission_amount', 12, 2);
                $table->string('currency', 8)->default('BDT');
                $table->string('status', 24)->default('pending');
                $table->foreignId('payout_request_id')->nullable()->index();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['referred_user_id', 'source_type', 'source_id'], 'ref_commission_source_unique');
                $table->index(['referrer_user_id', 'status']);
            });
        }

        if (!Schema::hasTable('referral_payout_requests')) {
            Schema::create('referral_payout_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('owner_type', 20);
                $table->decimal('amount', 12, 2);
                $table->string('currency', 8)->default('BDT');
                $table->string('method', 24);
                $table->text('account_details_encrypted');
                $table->string('status', 24)->default('pending');
                $table->text('admin_note')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Financial ledgers are intentionally preserved when this optional plugin is uninstalled.
    }
};