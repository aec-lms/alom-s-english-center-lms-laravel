<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('exams', 'max_attempts')) {
            Schema::table('exams', function (Blueprint $table): void {
                $table->unsignedSmallInteger('max_attempts')->default(1);
            });
        }

        if (!Schema::hasColumn('exam_attempts', 'started_at')) {
            Schema::table('exam_attempts', function (Blueprint $table): void {
                $table->timestamp('started_at')->nullable()->index();
            });
        }

        if (Schema::hasColumn('exam_attempts', 'submitted_answers')) {
            Schema::table('exam_attempts', function (Blueprint $table): void {
                $table->json('submitted_answers')->nullable()->change();
            });
        }

        if (Schema::hasColumn('exam_attempts', 'submitted_at')) {
            Schema::table('exam_attempts', function (Blueprint $table): void {
                $table->timestamp('submitted_at')->nullable()->change();
            });
        }

        // Historical completed attempts predate the timer. Preserve them and
        // use their submission time as a harmless start-time fallback.
        if (Schema::hasColumn('exam_attempts', 'started_at')) {
            \Illuminate\Support\Facades\DB::table('exam_attempts')
                ->whereNull('started_at')
                ->whereNotNull('submitted_at')
                ->update(['started_at' => \Illuminate\Support\Facades\DB::raw('submitted_at')]);
        }
    }

    public function down(): void
    {
        // LMS plugin data and exam history are preserved on plugin uninstall.
    }
};
