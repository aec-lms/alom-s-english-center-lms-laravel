<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('student_trace_events')) {
            Schema::create('student_trace_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('student_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->string('event_type', 80)->index();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();
                $table->index(['branch_id', 'occurred_at'], 'student_trace_branch_time_idx');
                $table->index(['student_id', 'occurred_at'], 'student_trace_student_time_idx');
            });
        }

        // Preserve older trace rows where a previous version shared the
        // Student Tracking table. The source table is never modified or deleted.
        if (!Schema::hasTable('student_activity_events')) {
            return;
        }

        $sourceHasStudent = Schema::hasColumn('student_activity_events', 'student_id');
        $sourceHasBranch = Schema::hasColumn('student_activity_events', 'branch_id');
        $sourceHasActor = Schema::hasColumn('student_activity_events', 'actor_id');
        $sourceHasType = Schema::hasColumn('student_activity_events', 'event_type');
        $sourceHasMetadata = Schema::hasColumn('student_activity_events', 'metadata');
        $sourceHasOccurred = Schema::hasColumn('student_activity_events', 'occurred_at');

        if (!$sourceHasType || !$sourceHasOccurred) {
            return;
        }

        DB::table('student_activity_events')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($sourceHasStudent, $sourceHasBranch, $sourceHasActor, $sourceHasMetadata): void {
                foreach ($rows as $row) {
                    $payload = [
                        'student_id' => $sourceHasStudent ? ($row->student_id ?? null) : null,
                        'branch_id' => $sourceHasBranch ? ($row->branch_id ?? null) : null,
                        'actor_id' => $sourceHasActor ? ($row->actor_id ?? null) : null,
                        'event_type' => (string) $row->event_type,
                        'metadata' => $sourceHasMetadata ? ($row->metadata ?? null) : null,
                        'occurred_at' => $row->occurred_at,
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => $row->updated_at ?? now(),
                    ];

                    DB::table('student_trace_events')->insertOrIgnore($payload);
                }
            });
    }

    public function down(): void
    {
        // Keep activity history on plugin rollback/uninstall.
    }
};
