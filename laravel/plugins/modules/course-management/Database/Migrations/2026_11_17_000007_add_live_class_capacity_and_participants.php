<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('live_classes') && !Schema::hasColumn('live_classes', 'capacity')) {
            Schema::table('live_classes', function (Blueprint $table): void {
                $table->unsignedSmallInteger('capacity')->default(300);
            });
        }

        if (!Schema::hasTable('live_class_participants')) {
            Schema::create('live_class_participants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('live_class_id')->constrained('live_classes')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('joined_at');
                $table->timestamp('last_seen_at')->index();
                $table->timestamp('left_at')->nullable()->index();
                $table->timestamps();
                $table->unique(['live_class_id', 'user_id'], 'live_class_participant_once');
                $table->index(['live_class_id', 'left_at', 'last_seen_at'], 'live_class_active_presence_idx');
            });
        }
    }

    public function down(): void
    {
        // Preserve participant audit history on plugin uninstall.
    }
};
