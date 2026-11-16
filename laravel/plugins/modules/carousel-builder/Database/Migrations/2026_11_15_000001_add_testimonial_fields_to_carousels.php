<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cms_carousels')) {
            return;
        }

        Schema::table('cms_carousels', function (Blueprint $table): void {
            if (!Schema::hasColumn('cms_carousels', 'author_name')) {
                $table->string('author_name', 160)->nullable();
            }
            if (!Schema::hasColumn('cms_carousels', 'badge_label')) {
                $table->string('badge_label', 80)->nullable();
            }
            if (!Schema::hasColumn('cms_carousels', 'quote')) {
                $table->text('quote')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Testimonial content is preserved when the carousel plugin is removed.
    }
};
