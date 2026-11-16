<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('fee_categories')) {
            Schema::create('fee_categories', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120)->unique();
                $table->decimal('default_amount', 10, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('fee_invoices')) {
            Schema::create('fee_invoices', function (Blueprint $table): void {
                $table->id();
                $table->string('invoice_number', 50)->unique();
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->foreignId('fee_category_id')->nullable()->constrained('fee_categories')->nullOnDelete();
                $table->decimal('subtotal_amount', 10, 2);
                $table->decimal('discount_amount', 10, 2)->default(0);
                $table->decimal('net_amount', 10, 2);
                $table->decimal('paid_amount', 10, 2)->default(0);
                $table->string('currency', 8)->default('BDT');
                $table->string('status', 24)->default('unpaid'); // unpaid, partial, paid, cancelled
                $table->date('due_date')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
