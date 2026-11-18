<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        $tables = [
            'alom_english_center_phonetic_attempts',
            'phonetic_exam_attempts',
            'phonetic_batch_memberships',
            'phonetic_audit_events',
        ];

        foreach ($tables as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'user_id')) {
                continue;
            }

            if ($tableName === 'phonetic_exam_attempts') {
                foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                    if (in_array('user_id', $foreignKey['columns'] ?? [], true)) {
                        Schema::table($tableName, function (Blueprint $table) use ($foreignKey): void {
                            $table->dropForeign($foreignKey['name']);
                        });
                    }
                }
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('user_id', 128)->change();
            });
        }
    }

    public function down(): void
    {
        // Keep widened identifiers: reverting to BIGINT would corrupt UUID/string host user IDs.
    }
};
