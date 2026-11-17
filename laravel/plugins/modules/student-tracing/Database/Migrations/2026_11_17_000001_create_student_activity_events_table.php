<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('student_activity_events')) {
            return;
        }

        Schema::create('student_activity_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('event_type', 64)->index();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['branch_id', 'student_id', 'occurred_at'], 'student_trace_branch_student_time_idx');
        });
    }

    public function down(): void
    {
        // Intentionally preserve trace history when the plugin is uninstalled.
    }
};
