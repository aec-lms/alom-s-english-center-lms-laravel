<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('live_classes')) {
            Schema::table('live_classes', function (Blueprint $table): void {
                if (!Schema::hasColumn('live_classes', 'teacher_user_id')) $table->unsignedBigInteger('teacher_user_id')->nullable()->index();
                if (!Schema::hasColumn('live_classes', 'teacher_rate_per_class')) $table->decimal('teacher_rate_per_class', 12, 2)->default(0);
                if (!Schema::hasColumn('live_classes', 'student_fee_per_class')) $table->decimal('student_fee_per_class', 12, 2)->default(0);
            });
        }

        if (!Schema::hasTable('class_session_records')) {
            Schema::create('class_session_records', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('live_class_id')->constrained('live_classes')->cascadeOnDelete();
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('teacher_user_id')->index();
                $table->unsignedBigInteger('completed_by')->nullable()->index();
                $table->timestamp('completed_at');
                $table->decimal('teacher_rate', 12, 2)->default(0);
                $table->decimal('student_fee_rate', 12, 2)->default(0);
                $table->unsignedInteger('enrolled_student_count')->default(0);
                $table->decimal('student_fees_total', 12, 2)->default(0);
                $table->string('status', 16)->default('completed');
                $table->text('completion_notes')->nullable();
                $table->timestamps();
                $table->unique('live_class_id', 'class_session_live_class_once');
            });
        }

        if (!Schema::hasTable('class_session_student_charges')) {
            Schema::create('class_session_student_charges', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('session_record_id')->constrained('class_session_records')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->unsignedBigInteger('fee_invoice_id')->index();
                $table->decimal('amount', 12, 2);
                $table->string('status', 16)->default('invoiced');
                $table->timestamps();
                $table->unique(['session_record_id', 'user_id'], 'class_session_student_charge_once');
                $table->unique('fee_invoice_id', 'class_session_invoice_once');
            });
        }

        if (!Schema::hasTable('teacher_class_payments')) {
            Schema::create('teacher_class_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('session_record_id')->constrained('class_session_records')->cascadeOnDelete();
                $table->unsignedBigInteger('teacher_user_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->decimal('amount', 12, 2);
                $table->string('status', 16)->default('pending');
                $table->unsignedBigInteger('paid_by')->nullable()->index();
                $table->timestamp('paid_at')->nullable();
                $table->string('payment_reference', 100)->nullable();
                $table->timestamps();
                $table->unique('session_record_id', 'teacher_class_payment_session_once');
            });
        }
    }

    public function down(): void
    {
        // Keep class completion, fee, and teacher-pay audit ledgers intact.
    }
};
