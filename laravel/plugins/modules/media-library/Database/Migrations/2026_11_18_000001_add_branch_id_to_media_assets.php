<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('media_assets') && !Schema::hasColumn('media_assets', 'branch_id')) {
            Schema::table('media_assets', function (Blueprint $table): void {
                $table->unsignedBigInteger('branch_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Keep branch ownership metadata to prevent accidental cross-branch exposure after rollback.
    }
};
