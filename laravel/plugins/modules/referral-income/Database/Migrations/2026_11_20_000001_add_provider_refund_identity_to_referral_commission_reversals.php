<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('referral_commission_reversals')) {
            return;
        }

        Schema::table('referral_commission_reversals', function (Blueprint $table): void {
            if (!Schema::hasColumn('referral_commission_reversals', 'provider_gateway')) {
                $table->string('provider_gateway', 80)->nullable();
            }
            if (!Schema::hasColumn('referral_commission_reversals', 'provider_refund_reference')) {
                $table->string('provider_refund_reference', 191)->nullable();
            }
            $table->unique(
                ['provider_gateway', 'provider_refund_reference'],
                'referral_reversal_provider_ref_unique'
            );
        });
    }

    public function down(): void
    {
        // Financial audit identifiers are intentionally retained on rollback.
    }
};
