<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('certificates')) {
            Schema::create('certificates', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('verification_code', 64)->unique();
                $table->string('student_name', 160);
                $table->string('student_code', 60)->nullable();
                $table->string('course_title', 200);
                $table->string('grade_or_band', 60)->default('Completed');
                $table->string('remarks', 240)->nullable();
                $table->date('issued_at');
                $table->string('status', 32)->default('issued'); // issued, revoked
                $table->unsignedBigInteger('issued_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
