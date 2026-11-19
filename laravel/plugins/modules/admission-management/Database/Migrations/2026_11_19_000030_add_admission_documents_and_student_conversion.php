<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('admission_applications')) {
            return;
        }

        Schema::table('admission_applications', function (Blueprint $table): void {
            if (!Schema::hasColumn('admission_applications', 'converted_student_id')) {
                $table->unsignedBigInteger('converted_student_id')->nullable()->index();
            }
            if (!Schema::hasColumn('admission_applications', 'converted_at')) {
                $table->timestamp('converted_at')->nullable();
            }
            if (!Schema::hasColumn('admission_applications', 'admission_fee_invoice_id')) {
                $table->unsignedBigInteger('admission_fee_invoice_id')->nullable()->index();
            }
        });

        if (!Schema::hasTable('admission_documents')) {
            Schema::create('admission_documents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('admission_application_id')->constrained('admission_applications')->cascadeOnDelete();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('disk', 32)->default('local');
                $table->string('path', 500);
                $table->string('original_name', 255);
                $table->string('mime_type', 120);
                $table->unsignedBigInteger('size_bytes');
                $table->char('sha256', 64);
                $table->timestamps();
                $table->index(['admission_application_id', 'created_at'], 'admission_documents_app_created_idx');
            });
        }
    }

    public function down(): void
    {
        // Applicant records and uploaded evidence are retained during plugin rollback.
    }
};
