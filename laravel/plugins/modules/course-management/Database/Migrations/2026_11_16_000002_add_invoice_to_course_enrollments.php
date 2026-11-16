<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('enrollments') && !Schema::hasColumn('enrollments', 'invoice_id')) {
            Schema::table('enrollments', function (Blueprint $table): void {
                $table->unsignedBigInteger('invoice_id')->nullable()->index()->after('branch_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('enrollments') && Schema::hasColumn('enrollments', 'invoice_id')) {
            Schema::table('enrollments', function (Blueprint $table): void {
                $table->dropColumn('invoice_id');
            });
        }
    }
};
