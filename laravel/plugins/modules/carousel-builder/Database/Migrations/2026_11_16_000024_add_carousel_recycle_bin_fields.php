<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cms_carousels')) {
            Schema::table('cms_carousels', function (Blueprint $table): void {
                if (!Schema::hasColumn('cms_carousels', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cms_carousels') && Schema::hasColumn('cms_carousels', 'deleted_at')) {
            Schema::table('cms_carousels', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
