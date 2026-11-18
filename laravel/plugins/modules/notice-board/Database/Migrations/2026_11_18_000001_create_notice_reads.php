<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('notice_reads')) {
            Schema::create('notice_reads', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('notice_id')->constrained('notices')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('read_at');
                $table->timestamps();
                $table->unique(['notice_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        // Read receipts are retained for audit and engagement history.
    }
};
