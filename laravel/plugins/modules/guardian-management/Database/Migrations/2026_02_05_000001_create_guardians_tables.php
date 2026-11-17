<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('guardians')) {
            Schema::create('guardians', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('relationship', 60)->default('parent');
                $table->string('occupation', 120)->nullable();
                $table->string('emergency_phone', 32)->nullable();
                $table->timestamps();
            });
        }

        // Student links are optional: do not create a pivot table when Student Management is absent.
        if (Schema::hasTable('students') && !Schema::hasTable('guardian_student')) {
            Schema::create('guardian_student', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('guardian_id')->constrained('guardians')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->boolean('is_primary')->default(true);
                $table->timestamps();
                $table->unique(['guardian_id', 'student_id']);
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
