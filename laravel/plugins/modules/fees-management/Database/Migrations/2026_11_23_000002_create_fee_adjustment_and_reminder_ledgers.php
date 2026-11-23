<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('fee_invoice_adjustments')) {
            Schema::create('fee_invoice_adjustments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('fee_invoice_id')->constrained('fee_invoices')->restrictOnDelete();
                $table->unsignedBigInteger('approved_by')->nullable()->index();
                $table->decimal('amount', 12, 2);
                $table->decimal('previous_net_amount', 12, 2);
                $table->decimal('new_net_amount', 12, 2);
                $table->string('adjustment_type', 24)->default('waiver');
                $table->string('reason', 255);
                $table->timestamps();
                $table->index(['fee_invoice_id', 'created_at']);
            });
        }
        if (!Schema::hasTable('fee_invoice_reminders')) {
            Schema::create('fee_invoice_reminders', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('fee_invoice_id')->constrained('fee_invoices')->restrictOnDelete();
                $table->unsignedBigInteger('sent_by')->nullable()->index();
                $table->string('recipient_email', 180);
                $table->string('status', 16);
                $table->text('failure_reason')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->index(['fee_invoice_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        // Keep financial adjustment and reminder audit history.
    }
};
