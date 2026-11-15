<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_items', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20)->default('product')->index(); // product | ebook
            $table->string('slug', 190)->unique();
            $table->string('title', 200);
            $table->string('category', 100)->default('General');
            $table->string('badge', 60)->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('original_price', 10, 2)->default(0);
            $table->boolean('is_free')->default(false);
            $table->integer('pages')->default(60);
            $table->integer('stock_count')->default(50);
            $table->text('summary')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('download_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_items');
    }
};
