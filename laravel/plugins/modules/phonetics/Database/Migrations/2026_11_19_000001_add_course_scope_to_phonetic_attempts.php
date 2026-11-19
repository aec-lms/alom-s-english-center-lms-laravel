<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('alom_english_center_phonetic_attempts')) {
            return;
        }

        if (!Schema::hasColumn('alom_english_center_phonetic_attempts', 'course_ref')) {
            Schema::table('alom_english_center_phonetic_attempts', function (Blueprint $table): void {
                $table->string('course_ref', 128)->nullable()->after('entry_id');
                $table->index(['user_id', 'course_ref', 'entry_id'], 'phonetic_attempt_course_scope_idx');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('alom_english_center_phonetic_attempts')
            || !Schema::hasColumn('alom_english_center_phonetic_attempts', 'course_ref')) {
            return;
        }

        Schema::table('alom_english_center_phonetic_attempts', function (Blueprint $table): void {
            $table->dropIndex('phonetic_attempt_course_scope_idx');
            $table->dropColumn('course_ref');
        });
    }
};
