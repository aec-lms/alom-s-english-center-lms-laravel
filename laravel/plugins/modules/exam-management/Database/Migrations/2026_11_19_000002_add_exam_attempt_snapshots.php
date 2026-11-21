<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('exam_attempts') && !Schema::hasColumn('exam_attempts', 'exam_snapshot')) {
            Schema::table('exam_attempts', function (Blueprint $table): void {
                $table->json('exam_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Exam attempt records are permanent academic history. Keep snapshots
        // on rollback rather than risking deletion of evidence used for grading.
    }
};
