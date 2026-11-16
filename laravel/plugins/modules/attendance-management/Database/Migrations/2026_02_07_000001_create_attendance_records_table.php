<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('attendance_records')) {
            Schema::create('attendance_records', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('batch_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('branch_id')->index();
                $table->date('attendance_date');
                $table->string('status', 20)->default('present'); // present, absent, late, excused
                $table->string('remarks', 255)->nullable();
                $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['batch_id', 'student_id', 'attendance_date']);
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
