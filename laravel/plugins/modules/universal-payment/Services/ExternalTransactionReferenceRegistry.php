<?php

declare(strict_types=1);

namespace Plugins\Modules\UniversalPayment\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ExternalTransactionReferenceRegistry
{
    /**
     * Reserve a gateway transaction reference inside the caller's DB transaction.
     * Repeated callbacks for the same LMS transaction are idempotent; reuse by a
     * different LMS transaction is rejected even under concurrent requests.
     */
    public function claim(string $gateway, string $externalReference, int $transactionId): void
    {
        $gatewayKey = strtoupper(trim($gateway));
        $referenceKey = strtoupper(trim($externalReference));

        if ($gatewayKey === '' || $referenceKey === '' || $transactionId < 1) {
            throw ValidationException::withMessages([
                'external_trx_id' => 'A valid payment provider reference is required.',
            ]);
        }

        if (!Schema::hasTable('universal_payment_reference_claims')) {
            throw new RuntimeException('Payment reference replay protection is not installed. Apply the Universal Payment migration before recording payments.');
        }

        $existing = DB::table('universal_payment_reference_claims')
            ->where('gateway', $gatewayKey)
            ->where('external_trx_id', $referenceKey)
            ->first();

        if ($existing) {
            if ((int) $existing->transaction_id === $transactionId) {
                return;
            }

            throw ValidationException::withMessages([
                'external_trx_id' => 'This payment reference has already been used for another transaction.',
            ]);
        }

        try {
            DB::table('universal_payment_reference_claims')->insert([
                'gateway' => $gatewayKey,
                'external_trx_id' => $referenceKey,
                'transaction_id' => $transactionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            // The unique key is the concurrency-safe final guard when two
            // requests attempt to claim the same provider reference at once.
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            if (in_array($sqlState, ['23000', '23505'], true)) {
                $owner = DB::table('universal_payment_reference_claims')
                    ->where('gateway', $gatewayKey)
                    ->where('external_trx_id', $referenceKey)
                    ->value('transaction_id');

                if ((int) $owner === $transactionId) {
                    return;
                }

                throw ValidationException::withMessages([
                    'external_trx_id' => 'This payment reference has already been used for another transaction.',
                ]);
            }

            throw $exception;
        }
    }
}
