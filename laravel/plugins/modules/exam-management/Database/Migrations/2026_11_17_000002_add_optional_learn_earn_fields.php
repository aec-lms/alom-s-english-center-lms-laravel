<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('learn_earn_categories') && Schema::hasTable('exams')
            && !Schema::hasColumn('exams', 'learn_earn_type')) {
            Schema::table('exams', function (Blueprint $table): void {
                $table->string('learn_earn_type', 12)->default('free');
                $table->decimal('access_price', 10, 2)->default(0);
            });
        }

        if (Schema::hasTable('learn_earn_questions') && Schema::hasTable('exam_questions')
            && !Schema::hasColumn('exam_questions', 'negative_marks')) {
            Schema::table('exam_questions', function (Blueprint $table): void {
                $table->decimal('negative_marks', 8, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        // Keep optional integration data and columns during module disable/uninstall.
    }
};
