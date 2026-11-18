<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('ai_assistant_budget_days')) {
            Schema::create('ai_assistant_budget_days', function (Blueprint $table): void {
                $table->date('budget_date')->primary();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ai_assistant_usage_reservations')) {
            Schema::create('ai_assistant_usage_reservations', function (Blueprint $table): void {
                $table->id();
                $table->date('budget_date');
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('role_slug', 64);
                $table->string('provider', 40);
                $table->string('model', 80);
                $table->unsignedInteger('prompt_tokens');
                $table->unsignedInteger('completion_limit');
                $table->string('status', 16)->default('pending');
                $table->unsignedInteger('actual_tokens')->nullable();
                $table->timestamp('expires_at');
                $table->timestamps();
                $table->index(['budget_date', 'status'], 'ai_assistant_usage_budget_status_idx');
                $table->index(['budget_date', 'user_id', 'status'], 'ai_assistant_usage_user_status_idx');
            });
        }
    }

    public function down(): void
    {
        // Usage and cost-control history is retained across plugin updates/uninstalls.
    }
};
