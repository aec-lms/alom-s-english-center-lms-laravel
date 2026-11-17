<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('guardians') && Schema::hasColumn('guardians', 'branch_id')
            && in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE guardians MODIFY branch_id BIGINT UNSIGNED NULL');
        }

        $this->createOptionalStudentPivot();
    }

    private function createOptionalStudentPivot(): void
    {
        if (!Schema::hasTable('guardians') || !Schema::hasTable('students') || Schema::hasTable('guardian_student')) {
            return;
        }

        Schema::create('guardian_student', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('guardian_id')->constrained('guardians')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();
            $table->unique(['guardian_id', 'student_id']);
        });
    }

    public function down(): void
    {
        // Preserve guardian records and relationship history during rollback.
    }
};
