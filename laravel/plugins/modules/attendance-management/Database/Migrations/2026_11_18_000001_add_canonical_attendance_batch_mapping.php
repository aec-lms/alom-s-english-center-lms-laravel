<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('attendance_batch_mappings')) {
            Schema::create('attendance_batch_mappings', function (Blueprint $table): void {
                $table->id();
                $table->string('source_type', 32);
                $table->unsignedBigInteger('source_id');
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('display_name', 180)->nullable();
                $table->timestamps();
                $table->unique(['source_type', 'source_id'], 'att_batch_map_source_unique');
            });
        }

        // Keep the historic attendance_records.batch_id value and FK untouched.
        // A mapping is an additional, explicit identity; never infer a study batch
        // from a colliding numeric ID.
        foreach ([
            'batches' => 'course_batch',
            'study_batches' => 'study_batch',
        ] as $tableName => $sourceType) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            DB::table($tableName)->orderBy('id')->chunk(500, function ($rows) use ($tableName, $sourceType): void {
                foreach ($rows as $row) {
                    DB::table('attendance_batch_mappings')->insertOrIgnore([
                        'source_type' => $sourceType,
                        'source_id' => (int) $row->id,
                        'branch_id' => $row->branch_id ?? null,
                        'display_name' => $row->name ?? $row->course_title ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
        }

        if (Schema::hasTable('attendance_records')
            && !Schema::hasColumn('attendance_records', 'attendance_batch_mapping_id')) {
            Schema::table('attendance_records', function (Blueprint $table): void {
                $table->unsignedBigInteger('attendance_batch_mapping_id')->nullable()->index();
            });
        }

        // Backfill only from the legacy FK target. This preserves every existing
        // record's exact batch identity, including when study_batches has the same ID.
        if (Schema::hasTable('attendance_records')
            && Schema::hasColumn('attendance_records', 'attendance_batch_mapping_id')
            && Schema::hasTable('batches')) {
            DB::table('attendance_records')
                ->whereNull('attendance_batch_mapping_id')
                ->orderBy('id')
                ->chunkById(500, function ($records): void {
                    foreach ($records as $record) {
                        $mappingId = DB::table('attendance_batch_mappings')
                            ->where('source_type', 'course_batch')
                            ->where('source_id', (int) $record->batch_id)
                            ->value('id');

                        if ($mappingId !== null) {
                            DB::table('attendance_records')
                                ->where('id', $record->id)
                                ->whereNull('attendance_batch_mapping_id')
                                ->update(['attendance_batch_mapping_id' => $mappingId]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        // Intentionally additive and retained on uninstall/rollback. Existing
        // attendance rows and their legacy batch_id FK must never be destroyed.
    }
};
