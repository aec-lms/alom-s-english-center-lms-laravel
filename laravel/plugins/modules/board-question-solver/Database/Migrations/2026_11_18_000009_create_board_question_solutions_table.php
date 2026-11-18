<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('board_question_solutions')) {
            Schema::create('board_question_solutions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('board', 80)->nullable();
                $table->unsignedSmallInteger('exam_year')->nullable();
                $table->string('class_level', 40)->nullable();
                $table->string('subject', 100);
                $table->string('question_type', 20);
                $table->string('mode', 20);
                $table->string('language', 2);
                $table->longText('question');
                $table->longText('solution');
                $table->timestamps();
                $table->index(['user_id', 'created_at'], 'board_solver_user_history_idx');
            });
        }
    }

    public function down(): void
    {
        // Student learning history is retained when the optional module is disabled or removed.
    }
};
