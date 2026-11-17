<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('universal_payment_transactions')) return;

        Schema::table('universal_payment_transactions', function (Blueprint $table): void {
            if (!Schema::hasColumn('universal_payment_transactions', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->index();
            }
            if (!Schema::hasColumn('universal_payment_transactions', 'storefront_item_id')) {
                $table->unsignedBigInteger('storefront_item_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        // Transaction history is retained.
    }
};