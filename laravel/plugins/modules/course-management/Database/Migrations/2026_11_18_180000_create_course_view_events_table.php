<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('course_view_events')) {
            return;
        }

        Schema::create('course_view_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->char('visitor_hash', 64);
            $table->date('viewed_on');
            $table->timestamps();
            $table->unique(['course_id', 'visitor_hash', 'viewed_on'], 'course_view_daily_unique');
            $table->index(['course_id', 'viewed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_view_events');
    }
};
