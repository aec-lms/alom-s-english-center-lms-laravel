<?php

declare(strict_types=1);

namespace Plugins\Modules\UniversalPayment\Services;

use App\Core\Plugins\PluginManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use RuntimeException;

class StorefrontPaymentGatewayService
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function initiateSslcommerz(UniversalPaymentTransaction $transaction, object $item, object $user, string $payerContact, array $metadata = []): string
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

        $transaction->update([
            'notes' => json_encode(array_merge($metadata, [
                'provider' => 'sslcommerz',
                'session_key' => (string) ($data['sessionkey'] ?? ''),
                'mode' => $sandbox ? 'sandbox' : 'live',
            ]), JSON_THROW_ON_ERROR),
        ]);

        return $checkoutUrl;
    }

    public function recordReferralPurchase(User $buyer, string $sourceType, string|int $sourceId, float $amount, string $currency): void
    {
        if ($amount <= 0 || !$this->plugins->isEnabled('referral-income')) {
            return;
        }

        app(\Plugins\Modules\ReferralIncome\Services\ReferralIncomeService::class)
            ->recordSettledPurchase($buyer, $sourceType, (string) $sourceId, $amount, $currency);
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

        $notes = json_decode((string) $transaction->notes, true);
        $notes = is_array($notes) ? $notes : [];

        if ((string) ($data['risk_level'] ?? '0') === '1') {
            DB::transaction(function () use ($transaction, $notes): void {
                $locked = UniversalPaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'verified') return;
                $locked->update([
                    'status' => 'risk_review',
                    'notes' => json_encode(array_merge($notes, ['provider' => 'sslcommerz', 'risk_review' => true, 'reason' => 'provider_risk_level_flag']), JSON_THROW_ON_ERROR),
                ]);
                if (($notes['purpose'] ?? null) === 'course_gift' && Schema::hasTable('course_gifts')) {
                    DB::table('course_gifts')->where('id', (int) ($notes['course_gift_id'] ?? 0))
                        ->where('status', 'awaiting_payment')->update(['status' => 'risk_review', 'updated_at' => now()]);
                }
                if (($notes['purpose'] ?? null) === 'course_gift_voucher' && Schema::hasTable('course_gift_vouchers')) {
                    DB::table('course_gift_vouchers')->where('id', (int) ($notes['course_gift_voucher_id'] ?? 0))
                        ->where('status', 'awaiting_payment')->update(['status' => 'risk_review', 'updated_at' => now()]);
                }
                if (($notes['purpose'] ?? null) === 'course_cart' && Schema::hasTable('course_cart_orders')) {
                    DB::table('course_cart_orders')->where('id', (int) ($notes['course_cart_order_id'] ?? 0))
                        ->where('status', 'awaiting_payment')->update(['status' => 'risk_review', 'updated_at' => now()]);
                }
            }, 3);
            throw new RuntimeException('SSLCOMMERZ flagged this payment for manual risk review. Course or gift access was not granted.');
        }

        // Claim the provider's bank transaction ID, not just its validation token,
        // so the same underlying payment cannot be replayed against a second order.
        $externalReference = trim((string) ($data['bank_tran_id'] ?? '')) ?: $validationId;
        $providerDetails = [
            'bank_transaction_id' => trim((string) ($data['bank_tran_id'] ?? '')),
            'validation_id' => $validationId,
            'validation_status' => strtoupper((string) $data['status']),
        ];
        if (($notes['purpose'] ?? null) === 'course_cart') {
            return $this->grantCourseCartAccess($transaction, $externalReference, $providerDetails, $notes);
        }
        if (($notes['purpose'] ?? null) === 'course_gift') {
            return $this->grantCourseGiftAccess($transaction, $externalReference, $providerDetails, $notes);
        }
        if (($notes['purpose'] ?? null) === 'course_gift_voucher') {
            return $this->grantCourseGiftVoucher($transaction, $externalReference, $providerDetails, $notes);
        }

        if ($transaction->learn_earn_exam_id) {
            return $this->grantExamAccess($transaction, $externalReference, $providerDetails);
        }

        return $this->grantEbookAccess($transaction, $externalReference, $providerDetails);
    }

    public function markSslcommerzFailed(array $callback, string $status): UniversalPaymentTransaction
    {
        $reference = trim((string) ($callback['tran_id'] ?? ''));
        abort_if($reference === '', 422, 'Missing payment reference.');
        $transaction = UniversalPaymentTransaction::query()->where('reference', $reference)->where('gateway', 'sslcommerz')->firstOrFail();

        return DB::transaction(function () use ($transaction, $status): UniversalPaymentTransaction {
            $locked = UniversalPaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return $locked->fresh();
            }

            $notes = json_decode((string) $locked->notes, true);
            $notes = is_array($notes) ? $notes : [];
            if (($notes['purpose'] ?? null) === 'course_gift') {
                $giftId = (int) ($notes['course_gift_id'] ?? 0);
                if ($giftId > 0 && Schema::hasTable('course_gifts')) {
                    DB::table('course_gifts')->where('id', $giftId)->where('status', 'awaiting_payment')
                        ->update(['status' => 'payment_failed', 'updated_at' => now()]);
                }
            }
            if (($notes['purpose'] ?? null) === 'course_gift_voucher') {
                $voucherId = (int) ($notes['course_gift_voucher_id'] ?? 0);
                if ($voucherId > 0 && Schema::hasTable('course_gift_vouchers')) {
                    DB::table('course_gift_vouchers')->where('id', $voucherId)->where('status', 'awaiting_payment')
                        ->update(['status' => 'payment_failed', 'updated_at' => now()]);
                }
            }
            if (($notes['purpose'] ?? null) === 'course_cart') {
                $orderId = (int) ($notes['course_cart_order_id'] ?? 0);
                if ($orderId > 0 && Schema::hasTable('course_cart_orders')) {
                    $order = DB::table('course_cart_orders')->where('id', $orderId)->lockForUpdate()->first();
                    if ($order && $order->status === 'awaiting_payment') {
                        DB::table('course_cart_orders')->where('id', $orderId)->update([
                            'status' => 'payment_failed',
                            'updated_at' => now(),
                        ]);
                        DB::table('course_carts')->where('id', $order->cart_id)->where('status', 'checkout_pending')
                            ->update(['status' => 'active', 'updated_at' => now()]);
                        if (filled($order->coupon_reservation_key) && Schema::hasTable('promo_coupon_redemptions')) {
                            (new \Plugins\Modules\MarketingManagement\Services\CourseCouponService())->release((string) $order->coupon_reservation_key);
                        }
                        if (filled($order->wallet_reservation_key) && Schema::hasTable('course_gift_wallet_transactions')) {
                            $walletEntry = DB::table('course_gift_wallet_transactions')->where('reference', $order->wallet_reservation_key)
                                ->where('status', 'reserved')->lockForUpdate()->first();
                            if ($walletEntry) {
                                $wallet = DB::table('course_gift_wallets')->where('id', $walletEntry->wallet_id)->lockForUpdate()->first();
                                if ($wallet) {
                                    DB::table('course_gift_wallets')->where('id', $wallet->id)->update([
                                        'balance' => round((float) $wallet->balance + (float) $walletEntry->amount, 2),
                                        'updated_at' => now(),
                                    ]);
                                }
                                DB::table('course_gift_wallet_transactions')->where('id', $walletEntry->id)
                                    ->update(['status' => 'reversed', 'updated_at' => now()]);
                            }
                        }
                    }
                }
            }

            $locked->update(['status' => $status]);
            return $locked->fresh();
        }, 3);
    }

 
    private function grantCourseCartAccess(UniversalPaymentTransaction $transaction, string $externalReference, array $providerDetails, array $notes): UniversalPaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $externalReference, $providerDetails, $notes): UniversalPaymentTransaction {
            $locked = UniversalPaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'verified') return $locked;

            $orderId = (int) ($notes['course_cart_order_id'] ?? 0);
            if ($orderId < 1 || !Schema::hasTable('course_cart_orders') || !Schema::hasTable('course_cart_order_items')
                || !Schema::hasTable('fee_invoices') || !Schema::hasTable('enrollments') || !Schema::hasTable('courses')) {
                throw new RuntimeException('The paid course cart order could not be located safely. Manual reconciliation is required.');
            }

            $order = DB::table('course_cart_orders')->where('id', $orderId)->lockForUpdate()->first();
            if (!$order || (int) $order->payment_transaction_id !== (int) $locked->id
                || (int) $order->user_id !== (int) $locked->user_id
                || abs(max(0, (float) $order->net_amount - (float) ($order->wallet_credit_amount ?? 0)) - (float) $locked->amount) > 0.01
                || strtoupper((string) $order->currency) !== strtoupper((string) $locked->currency)) {
                throw new RuntimeException('Course cart order and verified payment details do not match.');
            }

            if ($order->status === 'paid') {
                $locked->update([
                    'status' => 'verified',
                    'external_trx_id' => $externalReference,
                    'verified_at' => $locked->verified_at ?? now(),
                ]);
                return $locked->fresh();
            }

            if ($order->status !== 'awaiting_payment'
                || ($order->expires_at && now()->greaterThan($order->expires_at))) {
                $locked->update(['status' => 'risk_review', 'external_trx_id' => $externalReference,
                    'notes' => json_encode(array_merge($notes, ['risk_review' => true, 'reason' => 'course_cart_order_expired_or_not_payable']), JSON_THROW_ON_ERROR)]);
                DB::table('course_cart_orders')->where('id', $orderId)->update(['status' => 'risk_review', 'updated_at' => now()]);
                return $locked->fresh();
            }

            $items = DB::table('course_cart_order_items')->where('order_id', $orderId)->orderBy('position')->get();
            if ($items->isEmpty()) {
                throw new RuntimeException('The paid course cart order has no course items.');
            }
            $courseIds = $items->pluck('course_id')->map(fn ($id): int => (int) $id)->all();
            $courses = DB::table('courses')->whereIn('id', $courseIds)->where('status', 'published')->get()->keyBy('id');
            if ($courses->count() !== count($courseIds)) {
                $locked->update(['status' => 'risk_review', 'external_trx_id' => $externalReference,
                    'notes' => json_encode(array_merge($notes, ['risk_review' => true, 'reason' => 'course_unavailable_after_payment']), JSON_THROW_ON_ERROR)]);
                DB::table('course_cart_orders')->where('id', $orderId)->update(['status' => 'risk_review', 'updated_at' => now()]);
                return $locked->fresh();
            }

            $buyer = DB::table('users')->where('id', $locked->user_id)->first();
            if (!$buyer) {
                throw new RuntimeException('The course buyer account no longer exists.');
            }
            $buyerBranchId = $buyer->branch_id ?? null;
            if (Schema::hasTable('students') && Schema::hasColumn('students', 'user_id') && Schema::hasColumn('students', 'branch_id')) {
                $profileBranchId = DB::table('students')->where('user_id', $buyer->id)->value('branch_id');
                if ($profileBranchId !== null) $buyerBranchId = $profileBranchId;
            }
            foreach ($courses as $course) {
                $courseBranchId = $course->branch_id ?? null;
                if ($courseBranchId !== null && ((int) ($order->branch_id ?? 0) !== (int) $courseBranchId
                    || (int) ($buyerBranchId ?? 0) !== (int) $courseBranchId)) {
                    $locked->update(['status' => 'risk_review', 'external_trx_id' => $externalReference,
                        'notes' => json_encode(array_merge($notes, ['risk_review' => true, 'reason' => 'course_order_branch_mismatch']), JSON_THROW_ON_ERROR)]);
                    DB::table('course_cart_orders')->where('id', $orderId)->update(['status' => 'risk_review', 'updated_at' => now()]);
                    return $locked->fresh();
                }
            }

            $existingEnrollment = DB::table('enrollments')->where('user_id', $locked->user_id)
                ->whereIn('course_id', $courseIds)->whereIn('status', ['pending', 'active', 'completed'])->exists();
            if ($existingEnrollment) {
                $locked->update(['status' => 'risk_review', 'external_trx_id' => $externalReference,
                    'notes' => json_encode(array_merge($notes, ['risk_review' => true, 'reason' => 'enrollment_exists_after_checkout']), JSON_THROW_ON_ERROR)]);
                DB::table('course_cart_orders')->where('id', $orderId)->update(['status' => 'risk_review', 'updated_at' => now()]);
                return $locked->fresh();
            }

            if (Schema::hasTable('batches') && Schema::hasColumn('batches', 'status') && Schema::hasColumn('batches', 'course_id')
                && DB::table('batches')->whereIn('course_id', $courseIds)->where('status', 'open')->exists()) {
                $locked->update(['status' => 'risk_review', 'external_trx_id' => $externalReference,
                    'notes' => json_encode(array_merge($notes, ['risk_review' => true, 'reason' => 'open_batch_created_after_checkout']), JSON_THROW_ON_ERROR)]);
                DB::table('course_cart_orders')->where('id', $orderId)->update(['status' => 'risk_review', 'updated_at' => now()]);
                return $locked->fresh();
            }

            if (filled($order->coupon_reservation_key)) {
                if (!Schema::hasTable('promo_coupon_redemptions')) {
                    $locked->update(['status' => 'risk_review', 'external_trx_id' => $externalReference,
                        'notes' => json_encode(array_merge($notes, ['risk_review' => true, 'reason' => 'coupon_ledger_unavailable']), JSON_THROW_ON_ERROR)]);
                    DB::table('course_cart_orders')->where('id', $orderId)->update(['status' => 'risk_review', 'updated_at' => now()]);
                    return $locked->fresh();
                }
                $reservation = DB::table('promo_coupon_redemptions')->where('reservation_key', $order->coupon_reservation_key)->first();
                if (!$reservation || ($reservation->status !== 'redeemed'
                    && ($reservation->status !== 'reserved' || ($reservation->expires_at && now()->greaterThan($reservation->expires_at))))) {
                    $locked->update(['status' => 'risk_review', 'external_trx_id' => $externalReference,
                        'notes' => json_encode(array_merge($notes, ['risk_review' => true, 'reason' => 'coupon_reservation_expired']), JSON_THROW_ON_ERROR)]);
                    DB::table('course_cart_orders')->where('id', $orderId)->update(['status' => 'risk_review', 'updated_at' => now()]);
                    return $locked->fresh();
                }
                (new \Plugins\Modules\MarketingManagement\Services\CourseCouponService())->redeem((string) $order->coupon_reservation_key);
            }

            $user = DB::table('users')->where('id', $locked->user_id)->first();
            if (!$user) throw new RuntimeException('The course buyer account no longer exists.');
            $studentProfile = Schema::hasTable('students') ? DB::table('students')->where('user_id', $user->id)->first() : null;
            $invoice = \Plugins\Modules\FeesManagement\Models\FeeInvoice::query()->create([
                'invoice_number' => 'CART-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)),
                'student_id' => $studentProfile->id ?? null,
                'payer_name' => $user->name,
                'payer_email' => $user->email,
                'payer_phone' => $user->phone,
                'branch_id' => $order->branch_id,
                'subtotal_amount' => $order->subtotal_amount,
                'discount_amount' => $order->discount_amount,
                'net_amount' => $order->net_amount,
                'paid_amount' => $order->net_amount,
                'currency' => strtoupper((string) $order->currency),
                'status' => 'paid',
                'due_date' => now()->toDateString(),
            ]);

            foreach ($items as $item) {
                DB::table('enrollments')->insert([
                    'user_id' => $locked->user_id,
                    'course_id' => $item->course_id,
                    'branch_id' => $order->branch_id,
                    'invoice_id' => $invoice->id,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('course_cart_orders')->where('id', $orderId)->update([
                'invoice_id' => $invoice->id,
                'status' => 'paid',
                'paid_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('course_carts')->where('id', $order->cart_id)->update(['status' => 'completed', 'updated_at' => now()]);
            $buyerUser = User::query()->find((int) $locked->user_id);
            if ($buyerUser) {
                $this->recordReferralPurchase($buyerUser, 'course_cart_order', (string) $orderId,
                    (float) $order->net_amount, (string) $order->currency);
            }
            if (filled($order->wallet_reservation_key) && Schema::hasTable('course_gift_wallet_transactions')) {
                DB::table('course_gift_wallet_transactions')->where('reference', $order->wallet_reservation_key)
                    ->where('status', 'reserved')->update(['status' => 'completed', 'updated_at' => now()]);
            }

            app(\Plugins\Modules\UniversalPayment\Services\ExternalTransactionReferenceRegistry::class)
                ->claim((string) $locked->gateway, $externalReference, (int) $locked->id);
            $locked->update([
                'status' => 'verified',
                'external_trx_id' => $externalReference,
                'verified_at' => now(),
                'notes' => json_encode(array_merge($notes, ['provider' => $locked->gateway], $providerDetails), JSON_THROW_ON_ERROR),
            ]);

            return $locked->fresh();
        }, 3);
    }

    private function grantCourseGiftAccess(UniversalPaymentTransaction $transaction, string $externalReference, array $providerDetails, array $notes): UniversalPaymentTransaction
    {
        $verified = DB::transaction(function () use ($transaction, $externalReference, $providerDetails, $notes): UniversalPaymentTransaction {
            $locked = UniversalPaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'verified') return $locked;

            $giftId = (int) ($notes['course_gift_id'] ?? 0);
            if ($giftId < 1 || !Schema::hasTable('course_gifts') || !Schema::hasTable('courses')
                || !Schema::hasTable('fee_invoices') || !Schema::hasColumn('course_gifts', 'payment_transaction_id')) {
                throw new RuntimeException('The gift course order could not be located safely.');
            }

            $gift = DB::table('course_gifts')->where('id', $giftId)->lockForUpdate()->first();
            if (!$gift || (int) $gift->payment_transaction_id !== (int) $locked->id
                || (int) $gift->sender_user_id !== (int) $locked->user_id
                || abs((float) $gift->amount - (float) $locked->amount) > 0.01
                || strtoupper((string) $gift->currency) !== strtoupper((string) $locked->currency)) {
                throw new RuntimeException('Gift course order and verified payment details do not match.');
            }

            if (in_array($gift->status, ['paid', 'redeemed'], true)) {
                $locked->update(['status' => 'verified', 'external_trx_id' => $externalReference,
                    'verified_at' => $locked->verified_at ?? now()]);
                return $locked->fresh();
            }

            if ($gift->status !== 'awaiting_payment'
                || ($gift->expires_at && now()->greaterThan($gift->expires_at))) {
                $locked->update(['status' => 'risk_review', 'external_trx_id' => $externalReference,
                    'notes' => json_encode(array_merge($notes, ['risk_review' => true, 'reason' => 'gift_expired_or_not_payable']), JSON_THROW_ON_ERROR)]);
                DB::table('course_gifts')->where('id', $giftId)->update(['status' => 'risk_review', 'updated_at' => now()]);
                return $locked->fresh();
            }

            $course = DB::table('courses')->where('id', $gift->course_id)->where('status', 'published')->first();
            if (!$course || abs((float) $course->price - (float) $gift->amount) > 0.01
                || (($course->branch_id ?? null) !== null
                    && (int) $course->branch_id !== (int) ($locked->branch_id ?? 0))) {
                $locked->update(['status' => 'risk_review', 'external_trx_id' => $externalReference,
                    'notes' => json_encode(array_merge($notes, ['risk_review' => true, 'reason' => 'gift_course_price_or_availability_changed']), JSON_THROW_ON_ERROR)]);
                DB::table('course_gifts')->where('id', $giftId)->update(['status' => 'risk_review', 'updated_at' => now()]);
                return $locked->fresh();
            }

            $sender = DB::table('users')->where('id', $gift->sender_user_id)->first();
            if (!$sender) throw new RuntimeException('Gift sender account could not be found.');
            $studentProfile = Schema::hasTable('students') ? DB::table('students')->where('user_id', $sender->id)->first() : null;
            $invoice = FeeInvoice::query()->create([
                'invoice_number' => 'GIFT-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)),
                'student_id' => $studentProfile->id ?? null,
                'payer_name' => $sender->name,
                'payer_email' => $sender->email,
                'payer_phone' => $locked->payer_contact,
                'branch_id' => $locked->branch_id,
                'subtotal_amount' => $gift->amount,
                'discount_amount' => 0,
                'net_amount' => $gift->amount,
                'paid_amount' => $gift->amount,
                'currency' => strtoupper((string) $gift->currency),
                'status' => 'paid',
                'due_date' => now()->toDateString(),
            ]);

            DB::table('course_gifts')->where('id', $giftId)->update([
                'invoice_id' => $invoice->id,
                'status' => 'paid',
                'paid_at' => now(),
                'updated_at' => now(),
            ]);
            $senderUser = User::query()->find((int) $gift->sender_user_id);
            if ($senderUser) {
                $this->recordReferralPurchase($senderUser, 'course_gift', (string) $giftId,
                    (float) $gift->amount, (string) $gift->currency);
            }
            app(\Plugins\Modules\UniversalPayment\Services\ExternalTransactionReferenceRegistry::class)
                ->claim((string) $locked->gateway, $externalReference, (int) $locked->id);
            $locked->update([
                'status' => 'verified',
                'external_trx_id' => $externalReference,
                'verified_at' => now(),
                'notes' => json_encode(array_merge($notes, ['provider' => $locked->gateway], $providerDetails), JSON_THROW_ON_ERROR),
            ]);

            return $locked->fresh();
        }, 3);

        $giftId = (int) ($notes['course_gift_id'] ?? 0);
        if ($giftId > 0 && Schema::hasColumn('course_gifts', 'notification_sent_at')) {
            $gift = DB::table('course_gifts')->where('id', $giftId)->where('status', 'paid')->first();
            if ($gift && !$gift->notification_sent_at) {
                try {
                    $token = decrypt((string) $gift->redeem_token_encrypted);
                    $redeemUrl = url('/student/course-commerce/gifts/redeem/' . $token);
                    $courseTitle = (string) DB::table('courses')->where('id', $gift->course_id)->value('title');
                    $message = "You have received a course gift: {$courseTitle}\n\n";
                    if (filled($gift->recipient_name)) $message .= "Hello {$gift->recipient_name},\n\n";
                    if (filled($gift->message)) $message .= "Message from the sender: {$gift->message}\n\n";
                    $message .= "Sign in or register using this email address, then open the link below to redeem your gift:\n{$redeemUrl}\n\n";
                    $message .= "This gift expires on " . ($gift->expires_at ?? 'the expiry date shown in your account') . ".";
                    Mail::raw($message, function ($mail) use ($gift, $courseTitle): void {
                        $mail->to((string) $gift->recipient_email)->subject('You received a course gift: ' . $courseTitle);
                    });
                    DB::table('course_gifts')->where('id', $giftId)->whereNull('notification_sent_at')
                        ->update(['notification_sent_at' => now(), 'updated_at' => now()]);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $verified;
    }

    private function grantCourseGiftVoucher(UniversalPaymentTransaction $transaction, string $externalReference, array $providerDetails, array $notes): UniversalPaymentTransaction
    {
        $verified = DB::transaction(function () use ($transaction, $externalReference, $providerDetails, $notes): UniversalPaymentTransaction {
            $locked = UniversalPaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'verified') return $locked;

            $voucherId = (int) ($notes['course_gift_voucher_id'] ?? 0);
            if ($voucherId < 1 || !Schema::hasTable('course_gift_vouchers') || !Schema::hasTable('fee_invoices')
                || !Schema::hasColumn('course_gift_vouchers', 'payment_transaction_id')) {
                throw new RuntimeException('The gift voucher order could not be located safely.');
            }
            $voucher = DB::table('course_gift_vouchers')->where('id', $voucherId)->lockForUpdate()->first();
            if (!$voucher || (int) $voucher->payment_transaction_id !== (int) $locked->id
                || (int) $voucher->sender_user_id !== (int) $locked->user_id
                || abs((float) $voucher->amount - (float) $locked->amount) > 0.01
                || strtoupper((string) $voucher->currency) !== strtoupper((string) $locked->currency)) {
                throw new RuntimeException('Gift voucher order and verified payment details do not match.');
            }
            if (in_array($voucher->status, ['paid', 'redeemed'], true)) {
                $locked->update(['status' => 'verified', 'external_trx_id' => $externalReference,
                    'verified_at' => $locked->verified_at ?? now()]);
                return $locked->fresh();
            }
            if ($voucher->status !== 'awaiting_payment'
                || ($voucher->expires_at && now()->greaterThan($voucher->expires_at))) {
                $locked->update(['status' => 'risk_review', 'external_trx_id' => $externalReference,
                    'notes' => json_encode(array_merge($notes, ['risk_review' => true, 'reason' => 'voucher_expired_or_not_payable']), JSON_THROW_ON_ERROR)]);
                DB::table('course_gift_vouchers')->where('id', $voucherId)->update(['status' => 'risk_review', 'updated_at' => now()]);
                return $locked->fresh();
            }

            $sender = DB::table('users')->where('id', $voucher->sender_user_id)->first();
            if (!$sender) throw new RuntimeException('Gift voucher sender account could not be found.');
            $studentProfile = Schema::hasTable('students') ? DB::table('students')->where('user_id', $sender->id)->first() : null;
            $invoice = FeeInvoice::query()->create([
                'invoice_number' => 'VOUCHER-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)),
                'student_id' => $studentProfile->id ?? null,
                'payer_name' => $sender->name,
                'payer_email' => $sender->email,
                'payer_phone' => $locked->payer_contact,
                'branch_id' => $locked->branch_id,
                'subtotal_amount' => $voucher->amount,
                'discount_amount' => 0,
                'net_amount' => $voucher->amount,
                'paid_amount' => $voucher->amount,
                'currency' => strtoupper((string) $voucher->currency),
                'status' => 'paid',
                'due_date' => now()->toDateString(),
            ]);
            DB::table('course_gift_vouchers')->where('id', $voucherId)->update([
                'status' => 'paid', 'paid_at' => now(), 'updated_at' => now(),
            ]);
            $senderUser = User::query()->find((int) $voucher->sender_user_id);
            if ($senderUser) {
                $this->recordReferralPurchase($senderUser, 'course_gift_voucher', (string) $voucherId,
                    (float) $voucher->amount, (string) $voucher->currency);
            }
            app(\Plugins\Modules\UniversalPayment\Services\ExternalTransactionReferenceRegistry::class)
                ->claim((string) $locked->gateway, $externalReference, (int) $locked->id);
            $locked->update([
                'status' => 'verified',
                'external_trx_id' => $externalReference,
                'verified_at' => now(),
                'notes' => json_encode(array_merge($notes, ['provider' => $locked->gateway], $providerDetails), JSON_THROW_ON_ERROR),
            ]);
            return $locked->fresh();
        }, 3);

        $voucherId = (int) ($notes['course_gift_voucher_id'] ?? 0);
        if ($voucherId > 0 && Schema::hasColumn('course_gift_vouchers', 'notification_sent_at')) {
            $voucher = DB::table('course_gift_vouchers')->where('id', $voucherId)->where('status', 'paid')->first();
            if ($voucher && !$voucher->notification_sent_at) {
                try {
                    $token = decrypt((string) $voucher->redeem_token_encrypted);
                    $redeemUrl = url('/student/course-commerce/vouchers/redeem/' . $token);
                    $message = "You have received an AEC course gift voucher worth {$voucher->currency} " . number_format((float) $voucher->amount, 2) . ".\n\n";
                    if (filled($voucher->recipient_name)) $message .= "Hello {$voucher->recipient_name},\n\n";
                    if (filled($voucher->message)) $message .= "Message from the sender: {$voucher->message}\n\n";
                    $message .= "Sign in or register using this email address, then open this link to claim the voucher to your course wallet:\n{$redeemUrl}\n\n";
                    $message .= "The voucher expires on {$voucher->expires_at}.";
                    Mail::raw($message, function ($mail) use ($voucher): void {
                        $mail->to((string) $voucher->recipient_email)->subject('You received an AEC course gift voucher');
                    });
                    DB::table('course_gift_vouchers')->where('id', $voucherId)->whereNull('notification_sent_at')
                        ->update(['notification_sent_at' => now(), 'updated_at' => now()]);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $verified;
    }

    private function grantExamAccess(UniversalPaymentTransaction $transaction, string $externalReference, array $providerDetails): UniversalPaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $externalReference, $providerDetails): UniversalPaymentTransaction {
            $locked = UniversalPaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'verified') return $locked;

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
            $buyer = User::query()->find((int) $locked->user_id);
            if ($buyer) {
                $this->recordReferralPurchase($buyer, 'paid_exam', (string) $locked->id,
                    (float) $locked->amount, (string) $locked->currency);
            }

            return $locked->fresh();
        });
    }

    private function grantEbookAccess(UniversalPaymentTransaction $transaction, string $externalReference, array $providerDetails): UniversalPaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $externalReference, $providerDetails): UniversalPaymentTransaction {
            $locked = UniversalPaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'verified') return $locked;

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
            $buyer = User::query()->find((int) $locked->user_id);
            if ($buyer) {
                $this->recordReferralPurchase($buyer, 'ebook_purchase', (string) $locked->id,
                    (float) $locked->amount, (string) $locked->currency);
            }

            return $locked->fresh();
        });
    }
}