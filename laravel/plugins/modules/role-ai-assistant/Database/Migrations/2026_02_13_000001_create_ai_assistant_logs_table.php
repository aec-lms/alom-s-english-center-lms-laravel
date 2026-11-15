<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('ai_assistant_logs')) {
            Schema::create('ai_assistant_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role_slug', 64);
                $table->string('provider', 40);
                $table->string('model', 80);
                $table->text('prompt_excerpt');
                $table->unsignedInteger('tokens_estimated')->default(0);
                $table->string('status', 24)->default('completed');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
