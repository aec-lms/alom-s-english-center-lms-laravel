<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('course_bundles')) {
            Schema::create('course_bundles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->string('title', 180);
                $table->string('slug', 200)->unique();
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->string('status', 20)->default('draft')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('course_bundle_courses')) {
            Schema::create('course_bundle_courses', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('bundle_id')->constrained('course_bundles')->cascadeOnDelete();
                // Course Management is optional; deliberately no foreign key to courses.
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
                $table->unique(['bundle_id', 'course_id']);
            });
        }

        if (!Schema::hasTable('course_bundle_orders')) {
            Schema::create('course_bundle_orders', function (Blueprint $table): void {
                $table->id();
                // Snapshot records survive bundle/course deletion and plugin upgrades.
                $table->unsignedBigInteger('bundle_id')->nullable()->index();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                // Fees Management is optional; deliberately no foreign key to fee_invoices.
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('bundle_title', 180);
                $table->decimal('amount', 10, 2);
                $table->string('currency', 8)->default('BDT');
                $table->string('status', 24)->default('awaiting_payment')->index();
                $table->timestamp('purchased_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('course_bundle_order_courses')) {
            Schema::create('course_bundle_order_courses', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('order_id')->constrained('course_bundle_orders')->cascadeOnDelete();
                $table->unsignedBigInteger('course_id')->nullable()->index();
                $table->string('course_title', 180);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Order snapshots are intentionally retained by the LMS data-preservation policy.
    }
};
