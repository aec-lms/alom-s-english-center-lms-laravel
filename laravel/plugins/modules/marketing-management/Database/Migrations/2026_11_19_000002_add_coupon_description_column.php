<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Course-commerce coupon management persists a description even when the
        // legacy admin form omits it. Keep this additive for existing installations.
        if (Schema::hasTable('promo_coupons') && !Schema::hasColumn('promo_coupons', 'description')) {
            Schema::table('promo_coupons', function (Blueprint $table): void {
                $table->string('description', 200)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Preserve coupon metadata on rollback; this is intentionally non-destructive.
    }
};
