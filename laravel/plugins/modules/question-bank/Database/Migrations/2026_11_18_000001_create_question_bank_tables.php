<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('question_bank_questions')) {
            Schema::create('question_bank_questions', function (Blueprint $table): void {
                $table->id();
                $table->string('exam_category', 30)->index(); // bcs, bank_job, admission
                $table->string('question_type', 20)->index(); // mcq, written
                $table->string('subject', 120)->index();
                $table->string('topic', 160)->nullable()->index();
                $table->string('difficulty', 20)->default('medium')->index();
                $table->longText('prompt');
                $table->json('options')->nullable();
                $table->longText('correct_answer')->nullable();
                $table->longText('explanation')->nullable();
                $table->decimal('default_marks', 8, 2)->default(1);
                $table->decimal('default_negative_marks', 8, 2)->default(0);
                $table->json('tags')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('question_bank_papers')) {
            Schema::create('question_bank_papers', function (Blueprint $table): void {
                $table->id();
                $table->string('exam_category', 30)->index();
                $table->string('title', 180);
                $table->text('instructions')->nullable();
                $table->unsignedInteger('duration_minutes')->default(60);
                $table->decimal('total_marks', 10, 2)->default(0);
                $table->string('selection_mode', 20); // manual, random
                $table->json('selection_criteria')->nullable();
                $table->unsignedInteger('snapshot_version')->default(1);
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('question_bank_paper_items')) {
            Schema::create('question_bank_paper_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('paper_id')->constrained('question_bank_papers')->cascadeOnDelete();
                $table->unsignedBigInteger('question_id')->nullable()->index();
                $table->unsignedInteger('position');
                $table->json('question_snapshot');
                $table->decimal('marks', 8, 2);
                $table->decimal('negative_marks', 8, 2)->default(0);
                $table->timestamps();
                $table->unique(['paper_id', 'position']);
            });
        }
    }

    public function down(): void
    {
        // Plugin data is preserved on disable/uninstall. Purging is an explicit lifecycle action.
    }
};
