<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('payment_refunds') && !Schema::hasColumn('payment_refunds', 'provider_refund_reference')) {
            Schema::table('payment_refunds', function (Blueprint $table): void {
                $table->string('provider_refund_reference', 160)->nullable()->after('reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_refunds') && Schema::hasColumn('payment_refunds', 'provider_refund_reference')) {
            Schema::table('payment_refunds', function (Blueprint $table): void {
                $table->dropColumn('provider_refund_reference');
            });
        }
    }
};
