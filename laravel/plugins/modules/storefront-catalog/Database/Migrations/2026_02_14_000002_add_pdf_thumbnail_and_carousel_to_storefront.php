<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('storefront_items')) {
            Schema::table('storefront_items', function (Blueprint $table): void {
                if (!Schema::hasColumn('storefront_items', 'pdf_thumbnail_path')) {
                    $table->string('pdf_thumbnail_path', 255)->nullable()->after('thumbnail_path');
                }
                if (!Schema::hasColumn('storefront_items', 'is_carousel_featured')) {
                    $table->boolean('is_carousel_featured')->default(true)->after('is_active');
                }
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
