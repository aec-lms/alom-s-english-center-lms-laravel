<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_assets') && !Schema::hasColumn('media_assets', 'deleted_at')) {
            Schema::table('media_assets', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('media_assets') && Schema::hasColumn('media_assets', 'deleted_at')) {
            Schema::table('media_assets', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
