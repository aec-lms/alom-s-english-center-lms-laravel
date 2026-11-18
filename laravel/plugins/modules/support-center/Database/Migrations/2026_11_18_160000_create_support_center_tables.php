<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Deliberately no branch FK: Support Center remains installable without Branch Management.
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('subject', 160);
            $table->string('status', 20)->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['branch_id', 'status']);
            $table->index(['status', 'last_message_at']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->string('sender_role', 20);
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
            $table->index(['sender_role', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
