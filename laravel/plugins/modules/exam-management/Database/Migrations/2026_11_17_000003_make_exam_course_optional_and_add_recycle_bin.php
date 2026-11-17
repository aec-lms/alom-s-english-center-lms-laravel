<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('exams')) {
            if (Schema::hasColumn('exams', 'course_id')) {
                if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    DB::statement('ALTER TABLE exams MODIFY course_id BIGINT UNSIGNED NULL');
                } else {
                    Schema::table('exams', function (Blueprint $table): void {
                        $table->unsignedBigInteger('course_id')->nullable()->change();
                    });
                }
            }

            if (!Schema::hasColumn('exams', 'deleted_at')) {
                Schema::table('exams', function (Blueprint $table): void {
                    $table->softDeletes();
                });
            }

            if (Schema::hasColumn('exams', 'pass_marks')) {
                Schema::table('exams', function (Blueprint $table): void {
                    $table->decimal('pass_marks', 8, 2)->default(40)->change();
                });
            }
        }

        if (Schema::hasTable('exam_questions') && Schema::hasColumn('exam_questions', 'marks')) {
            Schema::table('exam_questions', function (Blueprint $table): void {
                $table->decimal('marks', 8, 2)->default(1)->change();
            });
        }

        if (Schema::hasTable('exam_attempts')) {
            Schema::table('exam_attempts', function (Blueprint $table): void {
                if (Schema::hasColumn('exam_attempts', 'score_obtained')) {
                    $table->decimal('score_obtained', 10, 2)->default(0)->change();
                }
                if (Schema::hasColumn('exam_attempts', 'total_marks')) {
                    $table->decimal('total_marks', 10, 2)->default(0)->change();
                }
            });
        }
    }

    public function down(): void
    {
        // Preserve standalone exam records and recycle-bin history.
    }
};
