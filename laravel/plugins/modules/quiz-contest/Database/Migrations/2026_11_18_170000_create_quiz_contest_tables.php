<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_contests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            // Branch Management is optional; retain a portable nullable reference.
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('prize_description', 500);
            $table->unsignedSmallInteger('winner_count')->default(1);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 20)->default('published')->index();
            $table->timestamp('drawn_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'starts_at', 'ends_at']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('quiz_contest_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_contest_id')->constrained('quiz_contests')->cascadeOnDelete();
            $table->text('question');
            $table->json('options');
            $table->unsignedTinyInteger('correct_option');
            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamps();
            $table->unique(['quiz_contest_id', 'position']);
        });

        Schema::create('quiz_contest_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_contest_id')->constrained('quiz_contests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('answers');
            $table->unsignedSmallInteger('score')->default(0);
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->unique(['quiz_contest_id', 'user_id']);
            $table->index(['quiz_contest_id', 'score', 'submitted_at']);
        });

        Schema::create('quiz_contest_winners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_contest_id')->constrained('quiz_contests')->cascadeOnDelete();
            $table->foreignId('quiz_contest_entry_id')->constrained('quiz_contest_entries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->string('prize_snapshot', 500);
            $table->foreignId('drawn_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('drawn_at');
            $table->timestamps();
            $table->unique(['quiz_contest_id', 'rank']);
            $table->unique('quiz_contest_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_contest_winners');
        Schema::dropIfExists('quiz_contest_entries');
        Schema::dropIfExists('quiz_contest_questions');
        Schema::dropIfExists('quiz_contests');
    }
};
