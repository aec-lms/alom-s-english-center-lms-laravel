<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('exams') || !Schema::hasColumn('exams', 'course_id')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE exams MODIFY course_id BIGINT UNSIGNED NULL');
            return;
        }

        Schema::table('exams', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Preserve standalone exams during rollback.
    }
};
