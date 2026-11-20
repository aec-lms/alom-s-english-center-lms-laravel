<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Services;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use RuntimeException;

class InvoicePaymentSessionReservationService
{
    private const ACTIVE_STATUSES = [
        'initiated',
        'pending',
        'risk_review',
        'processing',
        'authorized',
        'requires_action',
        'requires_confirmation',
        'requires_capture',
    ];

    /**
     * Reserve the outstanding invoice balance for exactly one provider session.
     *
     * The invoice row is the common serialization key across gateways. The
     * transaction row is inserted while that lock is held, so a competing
     * gateway request must observe the reservation after it acquires the lock.
     *
     * @return array{invoice: FeeInvoice, transaction: PaymentTransaction, remaining: float, reused: bool}
     */
    public function reserve(
        FeeInvoice $invoice,
        string $gatewaySlug,
        string $transactionPrefix,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): array {
        return DB::transaction(function () use ($invoice, $gatewaySlug, $transactionPrefix, $metadata, $idempotencyKey): array {
            $lockedInvoice = FeeInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $remaining = round((float) $lockedInvoice->net_amount - (float) $lockedInvoice->paid_amount, 2);
            $normalizedKey = $idempotencyKey !== null ? trim($idempotencyKey) : '';
            $requestFingerprint = $this->requestFingerprint(
                $gatewaySlug,
                $remaining,
                strtoupper((string) $lockedInvoice->currency),
                $metadata,
            );

            if ($normalizedKey !== '') {
                $existingForKey = PaymentTransaction::query()
                    ->where('invoice_id', $lockedInvoice->id)
                    ->where('gateway_slug', $gatewaySlug)
                    ->where('metadata->idempotency_key', $normalizedKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingForKey) {
                    $storedFingerprint = (string) ($existingForKey->metadata['request_fingerprint'] ?? '');
                    $replayFingerprint = $this->requestFingerprint(
                        $gatewaySlug,
                        (float) $existingForKey->amount,
                        strtoupper((string) $lockedInvoice->currency),
                        $metadata,
                    );
                    abort_unless(
                        $storedFingerprint !== '' && hash_equals($storedFingerprint, $replayFingerprint),
                        409,
                        'This idempotency key was already used with different or unverifiable payment parameters. Start a new checkout only after reconciling the existing reservation.',
                    );

                    return [
                        'invoice' => $lockedInvoice,
                        'transaction' => $existingForKey,
                        'remaining' => round((float) $existingForKey->amount, 2),
                        'reused' => true,
                    ];
                }
            }
            if ($remaining <= 0 || in_array($lockedInvoice->status, ['paid', 'cancelled', 'void'], true)) {
                throw new RuntimeException('Invoice has no payable balance.');
            }

            $active = PaymentTransaction::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($active) {
                throw new RuntimeException(
                    'This invoice already has an active or ambiguous payment session (' . $active->gateway_slug . '). Reconcile or explicitly release that session before choosing another gateway.'
                );
            }

            $transaction = PaymentTransaction::query()->create([
                'transaction_ref' => $transactionPrefix . '-' . strtoupper(bin2hex(random_bytes(8))),
                'invoice_id' => $lockedInvoice->id,
                'branch_id' => $lockedInvoice->branch_id,
                'gateway_slug' => $gatewaySlug,
                'amount' => $remaining,
                'currency' => strtoupper((string) $lockedInvoice->currency),
                'status' => 'initiated',
                'metadata' => array_merge($this->safeReservationMetadata($metadata), [
                    'idempotency_key' => $normalizedKey !== '' ? $normalizedKey : null,
                    'request_fingerprint' => $normalizedKey !== '' ? $requestFingerprint : null,
                    'session_outcome' => 'reserved',
                ]),
            ]);

            return [
                'invoice' => $lockedInvoice,
                'transaction' => $transaction,
                'remaining' => $remaining,
                'reused' => false,
            ];
        }, 3);
    }

    /**
     * Bind an idempotency key to the complete normalized request without
     * persisting provider credentials or opaque payment-method tokens.
     */
    private function requestFingerprint(string $gatewaySlug, float $amount, string $currency, array $metadata): string
    {
        $normalized = $this->sortRecursively([
            'gateway' => $gatewaySlug,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => strtoupper($currency),
            'provider_parameters' => $this->normalizeFingerprintParameters($metadata),
        ]);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $metadata
     *  @return array<string, mixed>
     */
    /**
     * Canonicalize provider parameters for idempotency comparison. A Stripe
     * PaymentMethod ID remains in the fingerprint so a different method cannot
     * reuse the key, while credentials, client secrets and raw tokens are
     * excluded. Raw provider tokens are never persisted in transaction metadata.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function normalizeFingerprintParameters(array $parameters): array
    {
        $sensitiveKeys = [
            'token', 'payment_token', 'client_secret', 'secret', 'password',
            'authorization', 'card_number', 'cvc', 'store_passwd', 'api_key',
            'secret_key', 'access_token', 'refresh_token',
        ];

        foreach ($parameters as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                unset($parameters[$key]);
                continue;
            }

            if (is_array($value)) {
                $parameters[$key] = $this->normalizeFingerprintParameters($value);
            } elseif (is_string($value)) {
                $parameters[$key] = trim($value);
            }
        }

        return $this->sortRecursively($parameters);
    }

    private function safeReservationMetadata(array $metadata): array
    {
        foreach (array_keys($metadata) as $key) {
            if (in_array(strtolower((string) $key), [
                'payment_method_id', 'payment_token', 'token', 'client_secret',
                'secret', 'password', 'authorization', 'card_number', 'cvc',
            ], true)) {
                unset($metadata[$key]);
                continue;
            }
            if (is_array($metadata[$key])) {
                $metadata[$key] = $this->safeReservationMetadata($metadata[$key]);
            }
        }

        return $metadata;
    }

    private function sortRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursively($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    public function markCreated(
        PaymentTransaction $transaction,
        array $providerMetadata = [],
        ?string $status = null,
        ?string $externalReference = null,
    ): PaymentTransaction {
        return DB::transaction(function () use ($transaction, $providerMetadata, $status, $externalReference): PaymentTransaction {
            $locked = PaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            // A fast provider callback may settle the transaction before the
            // checkout HTTP response is persisted. Never let this late response
            // move a terminal ledger state backwards to initiated/failed.
            if (in_array($locked->status, ['settled', 'refunded', 'failed'], true)) {
                return $locked;
            }

            $payload = [
                'metadata' => array_merge($locked->metadata ?? [], $providerMetadata, [
                    'session_outcome' => 'created',
                    'session_created_at' => now()->toIso8601String(),
                ]),
            ];
            if ($status !== null && $locked->status === 'initiated') {
                $payload['status'] = $status;
            }
            if ($externalReference !== null && ($locked->external_reference === null || $locked->external_reference === '')) {
                $payload['external_reference'] = $externalReference;
            }
            $locked->update($payload);

            return $locked->fresh();
        }, 3);
    }

    /**
     * Keep an unknown provider outcome reserved. A timeout or malformed
     * successful response does not prove that the provider failed to create
     * a chargeable session.
     */
    public function markUnknown(PaymentTransaction $transaction, string $reasonCode): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $reasonCode): PaymentTransaction {
            $locked = PaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['settled', 'refunded', 'failed'], true)) {
                return $locked;
            }

            $locked->update([
                'metadata' => array_merge($locked->metadata ?? [], [
                    'session_outcome' => 'unknown',
                    'session_unknown_reason' => preg_replace('/[^a-z0-9_.-]/i', '_', $reasonCode),
                    'session_unknown_at' => now()->toIso8601String(),
                    'manual_reconciliation_required' => true,
                ]),
            ]);

            return $locked->fresh();
        }, 3);
    }

    /**
     * Release a reservation only after a definite provider rejection.
     */
    public function markRejected(PaymentTransaction $transaction, string $reasonCode): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $reasonCode): PaymentTransaction {
            $locked = PaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            // Definitive rejection may release only a still-active reservation.
            // A callback/refund that won the race is authoritative and immutable.
            if (in_array($locked->status, ['settled', 'refunded'], true)) {
                return $locked;
            }
            if ($locked->status !== 'initiated') {
                return $locked;
            }

            $locked->update([
                'status' => 'failed',
                'metadata' => array_merge($locked->metadata ?? [], [
                    'session_outcome' => 'rejected',
                    'session_rejection_code' => preg_replace('/[^a-z0-9_.-]/i', '_', $reasonCode),
                    'session_rejected_at' => now()->toIso8601String(),
                ]),
            ]);

            return $locked->fresh();
        }, 3);
    }
}
