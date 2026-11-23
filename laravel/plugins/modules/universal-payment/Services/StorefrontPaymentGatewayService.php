<?php

declare(strict_types=1);

namespace Plugins\Modules\UniversalPayment\Services;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Plugins\Modules\CourseManagement\Services\CourseCommercePaymentSettlementService;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use RuntimeException;

class StorefrontPaymentGatewayService
{
    private const TERMINAL_PAYMENT_STATUSES = ['failed', 'cancelled', 'refunded', 'reversed', 'rejected', 'chargeback'];
    public function __construct(private readonly PluginManager $plugins) {}

    public function initiateSslcommerz(UniversalPaymentTransaction $transaction, object $item, object $user, string $payerContact, array $context = []): string
    {
        if (!$this->plugins->isEnabled('gateway-sslcommerz')) {
            throw new RuntimeException('Enable the dedicated SSLCOMMERZ Gateway plugin before accepting e-book payments.');
        }
        $storeId = (string) $this->plugins->getSetting('gateway-sslcommerz', 'store_id', '');
        $storePassword = (string) $this->plugins->getSetting('gateway-sslcommerz', 'store_password', '');
        if ($storeId === '' || $storePassword === '') {
            throw new RuntimeException('Install and configure the SSLCOMMERZ Gateway plugin before accepting online payments.');
        }

        $phone = preg_replace('/[^0-9+]/', '', $payerContact);
        if (strlen(preg_replace('/\D/', '', $phone)) < 7 || !filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid payer phone number and account email are required for SSLCOMMERZ.');
        }

        $sandbox = $this->plugins->getSetting('gateway-sslcommerz', 'sandbox_mode', '1') === '1';
        $baseUrl = $sandbox ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
        $payload = [
            'store_id' => $storeId,
            'store_passwd' => $storePassword,
            'total_amount' => number_format((float) $transaction->amount, 2, '.', ''),
            'currency' => strtoupper((string) $transaction->currency),
            'tran_id' => $transaction->reference,
            'success_url' => url('/universal-payment/sslcommerz/success'),
            'fail_url' => url('/universal-payment/sslcommerz/failed'),
            'cancel_url' => url('/universal-payment/sslcommerz/cancelled'),
            'ipn_url' => url('/universal-payment/sslcommerz/ipn'),
            'cus_name' => mb_substr((string) $user->name, 0, 50),
            'cus_email' => mb_substr((string) $user->email, 0, 50),
            'cus_add1' => 'N/A',
            'cus_city' => 'Dhaka',
            'cus_postcode' => '1000',
            'cus_country' => 'Bangladesh',
            'cus_phone' => mb_substr($phone, 0, 20),
            'shipping_method' => 'NO',
            'num_of_item' => '1',
            'product_name' => mb_substr((string) $item->title, 0, 200),
            'product_category' => 'Digital Education',
            'product_profile' => 'non-physical-goods',
        ];

        $response = Http::asForm()->acceptJson()->timeout(25)->post($baseUrl . '/gwprocess/v4/api.php', $payload);
        $data = $response->json();
        $checkoutUrl = is_array($data) ? (string) ($data['GatewayPageURL'] ?? '') : '';
        $expectedHost = $sandbox ? 'sandbox.sslcommerz.com' : 'securepay.sslcommerz.com';

        if (!$response->successful() || !is_array($data)
            || strtoupper((string) ($data['status'] ?? '')) !== 'SUCCESS'
            || strtolower((string) parse_url($checkoutUrl, PHP_URL_SCHEME)) !== 'https'
            || strtolower((string) parse_url($checkoutUrl, PHP_URL_HOST)) !== $expectedHost) {
            $transaction->update(['status' => 'failed', 'notes' => 'SSLCOMMERZ session creation failed.']);
            throw new RuntimeException('SSLCOMMERZ could not create a valid hosted checkout session. Check gateway credentials and sandbox/live mode.');
        }

        $existingNotes = json_decode((string) $transaction->notes, true);
        $existingNotes = is_array($existingNotes) ? $existingNotes : [];
        $transaction->update([
            'notes' => json_encode(array_merge($existingNotes, $context, [
                'provider' => 'sslcommerz',
                'session_key' => (string) ($data['sessionkey'] ?? ''),
                'mode' => $sandbox ? 'sandbox' : 'live',
            ]), JSON_THROW_ON_ERROR),
        ]);

        return $checkoutUrl;
    }

    public function verifySslcommerz(array $callback): UniversalPaymentTransaction
    {
        $reference = trim((string) ($callback['tran_id'] ?? ''));
        $validationId = trim((string) ($callback['val_id'] ?? ''));
        if ($reference === '' || $validationId === '') {
            throw new RuntimeException('SSLCOMMERZ callback is missing transaction validation details.');
        }

        $transaction = UniversalPaymentTransaction::query()
            ->where('reference', $reference)
            ->where('gateway', 'sslcommerz')
            ->firstOrFail();

        if ($transaction->status === 'verified') return $transaction;
        if (in_array($transaction->status, self::TERMINAL_PAYMENT_STATUSES, true)) {
            throw new RuntimeException('A terminal payment cannot be re-verified or used to restore access.');
        }

        $storeId = (string) $this->plugins->getSetting('gateway-sslcommerz', 'store_id', '');
        $storePassword = (string) $this->plugins->getSetting('gateway-sslcommerz', 'store_password', '');
        if ($storeId === '' || $storePassword === '') throw new RuntimeException('SSLCOMMERZ validation credentials are unavailable.');

        $sandbox = $this->plugins->getSetting('gateway-sslcommerz', 'sandbox_mode', '1') === '1';
        $baseUrl = $sandbox ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
        $response = Http::acceptJson()->timeout(25)->get($baseUrl . '/validator/api/validationserverAPI.php', [
            'val_id' => $validationId,
            'store_id' => $storeId,
            'store_passwd' => $storePassword,
            'format' => 'json',
        ]);
        $data = $response->json();

        if (!$response->successful() || !is_array($data)
            || !in_array(strtoupper((string) ($data['status'] ?? '')), ['VALID', 'VALIDATED'], true)
            || !hash_equals($transaction->reference, (string) ($data['tran_id'] ?? ''))
            || strtoupper((string) ($data['currency'] ?? '')) !== strtoupper((string) $transaction->currency)
            || abs((float) ($data['amount'] ?? 0) - (float) $transaction->amount) > 0.01) {
            throw new RuntimeException(sprintf(
                'SSLCOMMERZ validation mismatch (status=%s, reference_match=%s, amount=%s/%s, currency=%s/%s).',
                strtoupper((string) ($data['status'] ?? 'missing')),
                hash_equals($transaction->reference, (string) ($data['tran_id'] ?? '')) ? 'yes' : 'no',
                (string) ($data['amount'] ?? 'missing'),
                (string) $transaction->amount,
                strtoupper((string) ($data['currency'] ?? 'missing')),
                strtoupper((string) $transaction->currency)
            ));
        }

        if ((string) ($data['risk_level'] ?? '0') === '1') {
            $existingNotes = json_decode((string) $transaction->notes, true);
            $existingNotes = is_array($existingNotes) ? $existingNotes : [];
            $transaction->update([
                'status' => 'risk_review',
                'notes' => json_encode(array_merge($existingNotes, ['provider' => 'sslcommerz', 'risk_review' => true]), JSON_THROW_ON_ERROR),
            ]);
            throw new RuntimeException('SSLCOMMERZ flagged this payment for manual risk review. E-book access was not granted.');
        }

        $providerDetails = [
            'bank_transaction_id' => (string) ($data['bank_tran_id'] ?? ''),
            'validation_status' => strtoupper((string) $data['status']),
        ];

        $notes = json_decode((string) $transaction->notes, true);
        $purpose = is_array($notes) ? ($notes['purpose'] ?? null) : null;
        if (in_array($purpose, ['course_cart', 'course_gift', 'course_gift_voucher'], true)) {
            return app(CourseCommercePaymentSettlementService::class)
                ->settle($transaction, $validationId, $providerDetails);
        }

        if ($transaction->learn_earn_exam_id) {
            return $this->grantExamAccess($transaction, $validationId, $providerDetails);
        }

        return $this->grantEbookAccess($transaction, $validationId, $providerDetails);
    }

    public function markSslcommerzFailed(array $callback, string $status): UniversalPaymentTransaction
    {
        $reference = trim((string) ($callback['tran_id'] ?? ''));
        abort_if($reference === '', 422, 'Missing payment reference.');
        $transaction = UniversalPaymentTransaction::query()
            ->where('reference', $reference)
            ->where('gateway', 'sslcommerz')
            ->firstOrFail();

        // Browser fail/cancel return URLs are not authenticated provider messages.
        // Never release a pending checkout from an untrusted redirect: the provider
        // may have captured the payment even if the browser was forged or interrupted.
        // Only verifySslcommerz() may move this transaction to a verified state;
        // ambiguous outcomes remain pending for reconciliation.
        return $transaction;
    }

 
    public function settleVerifiedGatewayPayment(UniversalPaymentTransaction $transaction, string $externalReference, array $providerDetails = []): UniversalPaymentTransaction
    {
        if ($transaction->fee_invoice_id !== null) {
            return DB::transaction(function () use ($transaction, $externalReference, $providerDetails): UniversalPaymentTransaction {
                $locked = UniversalPaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'verified') return $locked;
                if (in_array($locked->status, self::TERMINAL_PAYMENT_STATUSES, true)) {
                    throw new RuntimeException('A terminal payment cannot be settled.');
                }

                $invoice = DB::table('fee_invoices')->where('id', $locked->fee_invoice_id)->lockForUpdate()->first();
                if (!$invoice) throw new RuntimeException('The fee invoice linked to this payment no longer exists.');
                $remaining = round((float) $invoice->net_amount - (float) $invoice->paid_amount, 2);
                if ($remaining <= 0 || (float) $locked->amount > $remaining + 0.01) {
                    throw new RuntimeException('The payment amount exceeds the remaining invoice balance.');
                }

                app(ExternalTransactionReferenceRegistry::class)->claim(
                    (string) $locked->gateway,
                    $externalReference,
                    (int) $locked->id
                );

                if (!app(PluginManager::class)->isEnabled('payment-management')) {
                    throw new RuntimeException('Payment Management must be enabled for automatic invoice settlement.');
                }

                $payment = PaymentTransaction::query()->create([
                    'transaction_ref' => 'UPAY-' . strtoupper(bin2hex(random_bytes(10))),
                    'invoice_id' => $invoice->id,
                    'branch_id' => $invoice->branch_id ?? null,
                    'gateway_slug' => 'gateway-' . str_replace('_', '-', (string) $locked->gateway),
                    'amount' => $locked->amount,
                    'currency' => strtoupper((string) $locked->currency),
                    'status' => 'initiated',
                    'metadata' => [
                        'source' => 'universal-payment',
                        'universal_payment_transaction_id' => (int) $locked->id,
                        'provider_details' => $providerDetails,
                    ],
                ]);

                app(PaymentSettlementService::class)->settleTransaction(
                    $payment,
                    $externalReference,
                    array_merge(['source' => 'universal-payment'], $providerDetails)
                );

                $notes = json_decode((string) $locked->notes, true);
                $locked->update([
                    'external_trx_id' => $externalReference,
                    'status' => 'verified',
                    'verified_at' => now(),
                    'notes' => json_encode(array_merge(is_array($notes) ? $notes : [], $providerDetails), JSON_THROW_ON_ERROR),
                ]);

                return $locked->fresh();
            });
        }

        $notes = json_decode((string) $transaction->notes, true);
        $purpose = is_array($notes) ? ($notes['purpose'] ?? null) : null;

        if (in_array($purpose, ['course_cart', 'course_gift', 'course_gift_voucher'], true)) {
            return app(CourseCommercePaymentSettlementService::class)->settle($transaction, $externalReference, $providerDetails);
        }

        if ($transaction->learn_earn_exam_id) {
            return $this->grantExamAccess($transaction, $externalReference, $providerDetails);
        }

        return $this->grantEbookAccess($transaction, $externalReference, $providerDetails);
    }

    private function grantExamAccess(UniversalPaymentTransaction $transaction, string $externalReference, array $providerDetails): UniversalPaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $externalReference, $providerDetails): UniversalPaymentTransaction {
            $locked = UniversalPaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'verified') return $locked;
            if (in_array($locked->status, self::TERMINAL_PAYMENT_STATUSES, true)) {
                throw new RuntimeException('A terminal payment cannot be re-verified or used to restore access.');
            }

            if (!$locked->learn_earn_exam_id || !$locked->user_id) {
                throw new RuntimeException('This payment is not linked to a registered paid-exam student.');
            }

            $exam = DB::table('exams')->where('id', $locked->learn_earn_exam_id)->where('is_published', true)->lockForUpdate()->first();
            if (!$exam || ($exam->learn_earn_type ?? 'free') !== 'paid'
                || (float) $exam->access_price <= 0
                || abs((float) $exam->access_price - (float) $locked->amount) > 0.01) {
                throw new RuntimeException('The paid exam price changed or the exam is no longer available. Manual review is required.');
            }

            $existingAccess = DB::table('learn_earn_exam_access')
                ->where('exam_id', $exam->id)
                ->where('user_id', $locked->user_id)
                ->first();

            if ($existingAccess && $existingAccess->status === 'granted'
                && (string) ($existingAccess->payment_reference ?? '') !== $locked->reference) {
                $locked->update([
                    'status' => 'risk_review',
                    'external_trx_id' => $externalReference,
                    'notes' => json_encode([
                        'provider' => $locked->gateway,
                        'purpose' => 'learn_earn_exam',
                        'risk_review' => true,
                        'reason' => 'exam_access_already_granted_by_another_transaction',
                    ], JSON_THROW_ON_ERROR),
                ]);

                return $locked->fresh();
            }

            app(ExternalTransactionReferenceRegistry::class)->claim(
                (string) $locked->gateway,
                $externalReference,
                (int) $locked->id
            );

            DB::table('learn_earn_exam_access')->updateOrInsert(
                ['exam_id' => $exam->id, 'user_id' => $locked->user_id],
                [
                    'status' => 'granted',
                    'payment_reference' => $locked->reference,
                    'granted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $locked->update([
                'status' => 'verified',
                'external_trx_id' => $externalReference,
                'verified_at' => now(),
                'notes' => json_encode(array_merge(['provider' => $locked->gateway, 'purpose' => 'learn_earn_exam'], $providerDetails), JSON_THROW_ON_ERROR),
            ]);

            return $locked->fresh();
        });
    }

    private function grantEbookAccess(UniversalPaymentTransaction $transaction, string $externalReference, array $providerDetails): UniversalPaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $externalReference, $providerDetails): UniversalPaymentTransaction {
            $locked = UniversalPaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'verified') return $locked;
            if (in_array($locked->status, self::TERMINAL_PAYMENT_STATUSES, true)) {
                throw new RuntimeException('A terminal payment cannot be re-verified or used to restore access.');
            }

            if (!$locked->storefront_item_id || !$locked->user_id) {
                throw new RuntimeException('This payment is not linked to a registered e-book buyer.');
            }

            $item = DB::table('storefront_items')->where('id', $locked->storefront_item_id)->where('type', 'ebook')->where('is_active', true)->lockForUpdate()->first();
            if (!$item || (float) $item->price <= 0 || abs((float) $item->price - (float) $locked->amount) > 0.01) {
                throw new RuntimeException('The e-book price changed or the item is no longer available. Manual review is required.');
            }

            $existingAccess = DB::table('storefront_item_access')
                ->where('storefront_item_id', $locked->storefront_item_id)
                ->where('user_id', $locked->user_id)
                ->first();
            if ($existingAccess && (int) ($existingAccess->payment_transaction_id ?? 0) !== (int) $locked->id) {
                $locked->update([
                    'status' => 'risk_review',
                    'external_trx_id' => $externalReference,
                    'notes' => json_encode([
                        'provider' => $locked->gateway,
                        'risk_review' => true,
                        'reason' => 'ebook_access_already_granted_by_another_transaction',
                    ], JSON_THROW_ON_ERROR),
                ]);

                return $locked->fresh();
            }

            app(ExternalTransactionReferenceRegistry::class)->claim(
                (string) $locked->gateway,
                $externalReference,
                (int) $locked->id
            );

            DB::table('storefront_item_access')->updateOrInsert(
                ['storefront_item_id' => $locked->storefront_item_id, 'user_id' => $locked->user_id],
                [
                    'payment_transaction_id' => $locked->id,
                    'granted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $locked->update([
                'status' => 'verified',
                'external_trx_id' => $externalReference,
                'verified_at' => now(),
                'notes' => json_encode(array_merge(['provider' => $locked->gateway], $providerDetails), JSON_THROW_ON_ERROR),
            ]);

            return $locked->fresh();
        });
    }
}