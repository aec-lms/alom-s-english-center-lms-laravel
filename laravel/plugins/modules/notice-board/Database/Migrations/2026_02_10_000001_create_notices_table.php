<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('notices')) {
            Schema::create('notices', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('title', 180);
                $table->text('body');
                $table->string('audience_role', 40)->default('all'); // all, student, teacher, guardian
                $table->boolean('is_pinned')->default(false);
                $table->timestamp('published_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
