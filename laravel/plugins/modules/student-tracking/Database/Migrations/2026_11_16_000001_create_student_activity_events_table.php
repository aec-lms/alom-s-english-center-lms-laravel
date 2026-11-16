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
            // No foreign keys: the plugin installs without sibling academic modules.
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('event_type', 40)->index();
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'occurred_at']);
            $table->index(['user_id', 'event_type', 'occurred_at'], 'student_activity_user_event_time_idx');
        });
    }

    public function down(): void
    {
        // Preserve student history on uninstall; explicit data purge is a separate admin action.
    }
};
