<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $table): void {
                $table->id();
                // Branch Management is optional; keep the reference portable.
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('category', 80);
                $table->string('title', 160);
                $table->text('description')->nullable();
                $table->decimal('amount', 12, 2);
                $table->string('payment_method', 40)->default('cash');
                $table->string('reference', 120)->nullable();
                $table->date('expense_date');
                $table->string('status', 20)->default('pending')->index();
                $table->string('receipt_path')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['branch_id', 'expense_date']);
                $table->index(['category', 'expense_date']);
            });
        }
    }

    public function down(): void
    {
        // Financial records and receipt references are retained on uninstall.
    }
};
