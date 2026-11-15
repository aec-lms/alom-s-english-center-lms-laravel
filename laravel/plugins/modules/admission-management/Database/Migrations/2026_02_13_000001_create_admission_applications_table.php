<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('admission_applications')) {
            Schema::create('admission_applications', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('application_code', 40)->unique();
                $table->string('applicant_name', 160);
                $table->string('email', 160);
                $table->string('phone', 40);
                $table->string('guardian_phone', 40)->nullable();
                $table->string('desired_course', 160);
                $table->string('preferred_batch', 80)->nullable();
                $table->string('previous_education', 160)->nullable();
                $table->string('status', 32)->default('pending'); // pending, approved, rejected
                $table->text('reviewer_notes')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
