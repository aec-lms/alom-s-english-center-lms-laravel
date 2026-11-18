<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('learn_earn_questions')) {
            return;
        }

        Schema::table('learn_earn_questions', function (Blueprint $table): void {
            if (!Schema::hasColumn('learn_earn_questions', 'subject')) {
                $table->string('subject', 120)->nullable();
            }
            if (!Schema::hasColumn('learn_earn_questions', 'difficulty')) {
                $table->string('difficulty', 20)->default('medium');
            }
            if (!Schema::hasColumn('learn_earn_questions', 'explanation')) {
                $table->text('explanation')->nullable();
            }
            if (!Schema::hasColumn('learn_earn_questions', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Question bank metadata and author history are retained across updates.
    }
};
