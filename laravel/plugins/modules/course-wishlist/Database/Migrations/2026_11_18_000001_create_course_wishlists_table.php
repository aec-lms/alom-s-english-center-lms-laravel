<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('course_wishlists')) {
            return;
        }

        Schema::create('course_wishlists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Deliberately no FK to courses: this plugin remains installable without Course Management.
            $table->unsignedBigInteger('course_id');
            $table->timestamps();
            $table->unique(['user_id', 'course_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        // Keep student saves during plugin disable/uninstall; destructive purge is an explicit lifecycle action.
    }
};
