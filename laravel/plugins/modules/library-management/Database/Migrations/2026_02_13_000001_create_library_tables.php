<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('library_books')) {
            Schema::create('library_books', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('isbn', 40)->unique();
                $table->string('title', 200);
                $table->string('author', 160);
                $table->string('category', 80)->default('IELTS & Academic');
                $table->unsignedInteger('total_copies')->default(1);
                $table->unsignedInteger('available_copies')->default(1);
                $table->string('shelf_location', 60)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('library_loans')) {
            Schema::create('library_loans', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('library_book_id')->constrained('library_books')->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('borrower_name', 160);
                $table->string('borrower_code', 60);
                $table->date('borrowed_at');
                $table->date('due_at');
                $table->date('returned_at')->nullable();
                $table->decimal('fine_amount', 10, 2)->default(0);
                $table->string('status', 24)->default('borrowed'); // borrowed, returned
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
