<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('universal_payment_transactions')) {
            return;
        }

        if (!Schema::hasTable('universal_payment_reference_claims')) {
            Schema::create('universal_payment_reference_claims', function (Blueprint $table): void {
                $table->id();
                $table->string('gateway', 40);
                $table->string('external_trx_id', 120);
                $table->unsignedBigInteger('transaction_id');
                $table->timestamps();
                $table->unique(
                    ['gateway', 'external_trx_id'],
                    'universal_payment_gateway_external_ref_unique'
                );
                $table->index('transaction_id', 'universal_payment_ref_claim_tx_idx');
            });
        }

        // Preserve replay protection for existing receipts without failing on
        // historical duplicate rows: one existing transaction owns each key.
        $legacyClaims = DB::table('universal_payment_transactions')
            ->selectRaw('MIN(id) as transaction_id, UPPER(TRIM(gateway)) as gateway, UPPER(TRIM(external_trx_id)) as external_trx_id')
            ->whereNotNull('external_trx_id')
            ->whereRaw("TRIM(external_trx_id) <> ''")
            ->groupByRaw('UPPER(TRIM(gateway)), UPPER(TRIM(external_trx_id))')
            ->get();

        foreach ($legacyClaims as $claim) {
            DB::table('universal_payment_reference_claims')->insertOrIgnore([
                'gateway' => $claim->gateway,
                'external_trx_id' => $claim->external_trx_id,
                'transaction_id' => $claim->transaction_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Retain payment replay-protection records on rollback. Removing them
        // could make already-used bank/provider references reusable.
    }
};
