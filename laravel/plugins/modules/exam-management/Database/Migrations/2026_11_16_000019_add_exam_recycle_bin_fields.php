<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('exams')) {
            return;
        }

        Schema::table('exams', function (Blueprint $table): void {
            if (!Schema::hasColumn('exams', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('exams') || !Schema::hasColumn('exams', 'deleted_at')) {
            return;
        }

        Schema::table('exams', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
