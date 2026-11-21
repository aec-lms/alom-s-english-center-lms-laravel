<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\ReferralIncome\Services\ReferralIncomeService;
use Tests\TestCase;

final class ReferralPayoutConcurrencyTest extends TestCase
{
    public function test_mysql_concurrent_payout_requests_attach_an_approved_balance_exactly_once(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Payout row-lock contention is verified by the MySQL 8 workflow.');
        }

        $this->markSystemInstalled();
        $this->activatePlugins([
            'referral-income',
            'student-management',
            'payment-management',
            'fees-management',
            'commerce-cart',
        ]);

        $owner = $this->createUserWithRole('student');
        $buyer = $this->createUserWithRole('student');
        $service = app(ReferralIncomeService::class);

        DB::table('referral_income_rules')->where('referrer_type', 'student')->update([
            'commission_percent' => 10,
            'minimum_payout' => 500,
            'is_active' => true,
            'updated_at' => now(),
        ]);

        $service->applyCode($buyer, $service->createOrGetCode($owner));
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'PAYOUT-RACE-' . strtoupper(bin2hex(random_bytes(5))),
            'payer_name' => $buyer->name,
            'payer_email' => $buyer->email,
            'payer_phone' => '01700000000',
            'subtotal_amount' => 6000,
            'discount_amount' => 0,
            'net_amount' => 6000,
            'paid_amount' => 6000,
            'currency' => 'BDT',
            'status' => 'paid',
            'due_date' => now()->toDateString(),
        ]);
        $orderId = DB::table('commerce_orders')->insertGetId([
            'order_number' => 'PAYOUT-RACE-ORDER-' . strtoupper(bin2hex(random_bytes(4))),
            'user_id' => $buyer->id,
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'currency' => 'BDT',
            'subtotal' => 6000,
            'discount_total' => 0,
            'grand_total' => 6000,
            'status' => 'completed',
            'payment_status' => 'paid',
            'billing_snapshot' => json_encode(['name' => $buyer->name, 'email' => $buyer->email]),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $commissionId = $service->recordSettledPurchase($buyer, 'commerce_order', (string) $orderId, 6000, 'BDT');
        $this->assertNotNull($commissionId);
        DB::table('referral_commissions')->where('id', $commissionId)->update([
            'status' => 'approved',
            'updated_at' => now(),
        ]);

        $primaryName = DB::getDefaultConnection();
        $primary = DB::connection($primaryName);
        $secondaryName = 'referral_payout_secondary_' . bin2hex(random_bytes(3));
        $secondaryConfig = config('database.connections.' . $primaryName);
        $secondaryConfig['database'] = $primary->getDatabaseName();
        config(['database.connections.' . $secondaryName => $secondaryConfig]);
        DB::purge($secondaryName);
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $firstPayoutId = null;
        $primary->beginTransaction();
        try {
            // Run the real service while an outer transaction keeps its row locks
            // open. This models request A having reserved the approved balance
            // while request B reaches the same commission rows concurrently.
            $firstPayoutId = $service->requestPayout($owner, 'bkash', '01700000000');
            $this->assertDatabaseHas('referral_commissions', [
                'id' => $commissionId,
                'status' => 'payout_requested',
                'payout_request_id' => $firstPayoutId,
            ]);

            DB::setDefaultConnection($secondaryName);
            try {
                $service->requestPayout($owner, 'nagad', '01800000000');
                $this->fail('A concurrent request must not read or attach a commission already locked by another payout.');
            } catch (QueryException $exception) {
                $this->assertContains((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], $exception->getMessage());
            }

            DB::setDefaultConnection($primaryName);
            $primary->commit();

            DB::setDefaultConnection($secondaryName);
            try {
                $service->requestPayout($owner, 'nagad', '01800000000');
                $this->fail('A retry after the first payout commits must not attach the same commission a second time.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('payout', $exception->errors());
            }
        } catch (\Throwable $exception) {
            DB::setDefaultConnection($primaryName);
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            throw $exception;
        } finally {
            DB::setDefaultConnection($primaryName);
            DB::purge($secondaryName);
        }

        $this->assertSame(1, DB::table('referral_payout_requests')->where('user_id', $owner->id)->count());
        $this->assertSame(600.0, (float) DB::table('referral_payout_requests')->where('id', $firstPayoutId)->value('amount'));
        $this->assertDatabaseHas('referral_commissions', [
            'id' => $commissionId,
            'status' => 'payout_requested',
            'payout_request_id' => $firstPayoutId,
        ]);
    }

    public function test_mysql_concurrent_retry_of_the_same_refund_creates_one_commission_reversal(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Refund reversal row-lock contention is verified by the MySQL 8 workflow.');
        }

        [$service, $buyer, $admin, $orderId, $commissionId, $paymentId] = $this->createRefundFixture();
        $refundId = DB::table('payment_refunds')->insertGetId([
            'payment_transaction_id' => $paymentId,
            'approved_by' => $admin->id,
            'amount' => 1000,
            'reason' => 'Concurrent same-refund replay',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $primaryName = DB::getDefaultConnection();
        $primary = DB::connection($primaryName);
        $secondaryName = $this->createSecondaryMysqlConnection($primaryName);
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $primary->beginTransaction();
        try {
            $firstReversalId = $service->reverseSettledPurchase(
                $buyer,
                'commerce_order',
                (string) $orderId,
                (int) $refundId,
                1000,
                'BDT'
            );

            DB::setDefaultConnection($secondaryName);
            try {
                $service->reverseSettledPurchase(
                    $buyer,
                    'commerce_order',
                    (string) $orderId,
                    (int) $refundId,
                    1000,
                    'BDT'
                );
                $this->fail('A duplicate refund retry must wait on the same authoritative refund row.');
            } catch (QueryException $exception) {
                $this->assertContains((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], $exception->getMessage());
            }

            DB::setDefaultConnection($primaryName);
            $primary->commit();

            DB::setDefaultConnection($secondaryName);
            $retryReversalId = $service->reverseSettledPurchase(
                $buyer,
                'commerce_order',
                (string) $orderId,
                (int) $refundId,
                1000,
                'BDT'
            );
        } catch (\Throwable $exception) {
            DB::setDefaultConnection($primaryName);
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            throw $exception;
        } finally {
            DB::setDefaultConnection($primaryName);
            DB::purge($secondaryName);
        }

        $this->assertSame((int) $firstReversalId, (int) $retryReversalId);
        $this->assertSame(1, DB::table('referral_commission_reversals')->where('payment_refund_id', $refundId)->count());
        $this->assertDatabaseHas('referral_commissions', [
            'id' => $commissionId,
            'commission_amount' => 0,
            'status' => 'reversed',
        ]);
    }

    public function test_mysql_concurrent_distinct_refunds_preserve_cumulative_commission_reversal_amount(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Distinct refund serialization is verified by the MySQL 8 workflow.');
        }

        [$service, $buyer, $admin, $orderId, $commissionId, $paymentId] = $this->createRefundFixture();
        $firstRefundId = DB::table('payment_refunds')->insertGetId([
            'payment_transaction_id' => $paymentId,
            'approved_by' => $admin->id,
            'amount' => 400,
            'reason' => 'Concurrent partial refund one',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondRefundId = DB::table('payment_refunds')->insertGetId([
            'payment_transaction_id' => $paymentId,
            'approved_by' => $admin->id,
            'amount' => 600,
            'reason' => 'Concurrent partial refund two',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $primaryName = DB::getDefaultConnection();
        $primary = DB::connection($primaryName);
        $secondaryName = $this->createSecondaryMysqlConnection($primaryName);
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $primary->beginTransaction();
        try {
            $firstReversalId = $service->reverseSettledPurchase(
                $buyer,
                'commerce_order',
                (string) $orderId,
                (int) $firstRefundId,
                400,
                'BDT'
            );

            DB::setDefaultConnection($secondaryName);
            try {
                $service->reverseSettledPurchase(
                    $buyer,
                    'commerce_order',
                    (string) $orderId,
                    (int) $secondRefundId,
                    600,
                    'BDT'
                );
                $this->fail('A distinct refund for the same order must serialize on the source invoice/order/commission rows.');
            } catch (QueryException $exception) {
                $this->assertContains((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], $exception->getMessage());
            }

            DB::setDefaultConnection($primaryName);
            $primary->commit();

            DB::setDefaultConnection($secondaryName);
            $secondReversalId = $service->reverseSettledPurchase(
                $buyer,
                'commerce_order',
                (string) $orderId,
                (int) $secondRefundId,
                600,
                'BDT'
            );
        } catch (\Throwable $exception) {
            DB::setDefaultConnection($primaryName);
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            throw $exception;
        } finally {
            DB::setDefaultConnection($primaryName);
            DB::purge($secondaryName);
        }

        $this->assertNotSame((int) $firstReversalId, (int) $secondReversalId);
        $this->assertSame(2, DB::table('referral_commission_reversals')->where('referral_commission_id', $commissionId)->count());
        $this->assertSame(1000.0, (float) DB::table('referral_commission_reversals')->where('referral_commission_id', $commissionId)->sum('refunded_amount'));
        $this->assertSame(100.0, (float) DB::table('referral_commission_reversals')->where('referral_commission_id', $commissionId)->sum('commission_reversal_amount'));
        $this->assertDatabaseHas('referral_commissions', [
            'id' => $commissionId,
            'commission_amount' => 0,
            'status' => 'reversed',
        ]);
    }

    public function test_course_cart_refund_rejects_over_refund_wrong_buyer_invoice_mismatch_and_currency(): void
    {
        [$service, $buyer, $otherBuyer, $admin, $orderId, $commissionId, $invoiceId] =
            $this->createCourseCartRefundFixture();

        $overRefundPaymentId = DB::table('payment_transactions')->insertGetId([
            'transaction_ref' => 'COURSE-OVER-REFUND-' . strtoupper(bin2hex(random_bytes(4))),
            'invoice_id' => $invoiceId,
            'branch_id' => null,
            'gateway_slug' => 'offline',
            'amount' => 1001,
            'currency' => 'BDT',
            'status' => 'settled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $overRefundId = DB::table('payment_refunds')->insertGetId([
            'payment_transaction_id' => $overRefundPaymentId,
            'approved_by' => $admin->id,
            'amount' => 1001,
            'reason' => 'Course-cart over-refund rejection',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $service->reverseSettledPurchase($buyer, 'course_cart_order', (string) $orderId, $overRefundId, 1001, 'BDT');
            $this->fail('A course-cart refund must not exceed the remaining eligible purchase amount.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('exceeds the remaining eligible purchase amount', $exception->getMessage());
        }

        $wrongBuyerPaymentId = DB::table('payment_transactions')->insertGetId([
            'transaction_ref' => 'COURSE-WRONG-BUYER-' . strtoupper(bin2hex(random_bytes(4))),
            'invoice_id' => $invoiceId,
            'branch_id' => null,
            'gateway_slug' => 'offline',
            'amount' => 100,
            'currency' => 'BDT',
            'status' => 'settled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $wrongBuyerRefundId = DB::table('payment_refunds')->insertGetId([
            'payment_transaction_id' => $wrongBuyerPaymentId,
            'approved_by' => $admin->id,
            'amount' => 100,
            'reason' => 'Course-cart wrong buyer rejection',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $service->reverseSettledPurchase($otherBuyer, 'course_cart_order', (string) $orderId, $wrongBuyerRefundId, 100, 'BDT');
            $this->fail('A course-cart refund cannot be applied by a different buyer.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('does not belong to this buyer', $exception->getMessage());
        }

        $otherInvoice = FeeInvoice::query()->create([
            'invoice_number' => 'COURSE-OTHER-INVOICE-' . strtoupper(bin2hex(random_bytes(4))),
            'payer_name' => $buyer->name,
            'payer_email' => $buyer->email,
            'payer_phone' => '01700000000',
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'net_amount' => 1000,
            'paid_amount' => 1000,
            'currency' => 'BDT',
            'status' => 'paid',
            'due_date' => now()->toDateString(),
        ]);
        $wrongInvoicePaymentId = DB::table('payment_transactions')->insertGetId([
            'transaction_ref' => 'COURSE-WRONG-INVOICE-' . strtoupper(bin2hex(random_bytes(4))),
            'invoice_id' => $otherInvoice->id,
            'branch_id' => null,
            'gateway_slug' => 'offline',
            'amount' => 100,
            'currency' => 'BDT',
            'status' => 'settled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $wrongInvoiceRefundId = DB::table('payment_refunds')->insertGetId([
            'payment_transaction_id' => $wrongInvoicePaymentId,
            'approved_by' => $admin->id,
            'amount' => 100,
            'reason' => 'Course-cart wrong invoice rejection',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $service->reverseSettledPurchase($buyer, 'course_cart_order', (string) $orderId, $wrongInvoiceRefundId, 100, 'BDT');
            $this->fail('A course-cart refund from another invoice must not reverse this commission.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('does not belong to the source order invoice', $exception->getMessage());
        }

        $currencyPaymentId = DB::table('payment_transactions')->insertGetId([
            'transaction_ref' => 'COURSE-WRONG-CURRENCY-' . strtoupper(bin2hex(random_bytes(4))),
            'invoice_id' => $invoiceId,
            'branch_id' => null,
            'gateway_slug' => 'offline',
            'amount' => 100,
            'currency' => 'BDT',
            'status' => 'settled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $currencyRefundId = DB::table('payment_refunds')->insertGetId([
            'payment_transaction_id' => $currencyPaymentId,
            'approved_by' => $admin->id,
            'amount' => 100,
            'reason' => 'Course-cart wrong currency rejection',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $service->reverseSettledPurchase($buyer, 'course_cart_order', (string) $orderId, $currencyRefundId, 100, 'USD');
            $this->fail('A course-cart refund currency must match the authoritative order currency.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('currency does not match the source order', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('referral_commission_reversals')->where('referral_commission_id', $commissionId)->count());
        $this->assertDatabaseHas('referral_commissions', [
            'id' => $commissionId,
            'commission_amount' => 100,
            'status' => 'pending',
        ]);
    }

    private function createCourseCartRefundFixture(): array
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'referral-income',
            'student-management',
            'course-management',
            'fees-management',
            'universal-payment',
            'payment-management',
            'commerce-cart',
        ]);

        $referrer = $this->createUserWithRole('student');
        $buyer = $this->createUserWithRole('student');
        $otherBuyer = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $service = app(ReferralIncomeService::class);

        DB::table('referral_income_rules')->where('referrer_type', 'student')->update([
            'commission_percent' => 10,
            'minimum_payout' => 500,
            'is_active' => true,
            'updated_at' => now(),
        ]);
        $service->applyCode($buyer, $service->createOrGetCode($referrer));

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'COURSE-CART-REFUND-' . strtoupper(bin2hex(random_bytes(5))),
            'payer_name' => $buyer->name,
            'payer_email' => $buyer->email,
            'payer_phone' => '01700000000',
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'net_amount' => 1000,
            'paid_amount' => 1000,
            'currency' => 'BDT',
            'status' => 'paid',
            'due_date' => now()->toDateString(),
        ]);

        $cartId = DB::table('course_carts')->insertGetId([
            'user_id' => $buyer->id,
            'status' => 'completed',
            'currency' => 'BDT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $originalPaymentId = DB::table('universal_payment_transactions')->insertGetId([
            'reference' => 'COURSE-CART-ORIGINAL-' . strtoupper(bin2hex(random_bytes(4))),
            'payer_name' => $buyer->name,
            'payer_contact' => '01700000000',
            'item_title' => 'Referral Course Cart Order',
            'gateway' => 'offline',
            'currency' => 'BDT',
            'amount' => 1000,
            'external_trx_id' => 'COURSE-CART-VERIFIED-' . strtoupper(bin2hex(random_bytes(4))),
            'status' => 'verified',
            'notes' => json_encode(['purpose' => 'course_cart'], JSON_THROW_ON_ERROR),
            'user_id' => $buyer->id,
            'fee_invoice_id' => $invoice->id,
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('course_cart_orders')->insertGetId([
            'cart_id' => $cartId,
            'user_id' => $buyer->id,
            'branch_id' => null,
            'invoice_id' => $invoice->id,
            'payment_transaction_id' => $originalPaymentId,
            'coupon_id' => null,
            'coupon_code' => null,
            'coupon_reservation_key' => null,
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'net_amount' => 1000,
            'currency' => 'BDT',
            'status' => 'paid',
            'paid_at' => now(),
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('universal_payment_transactions')->where('id', $originalPaymentId)->update([
            'notes' => json_encode([
                'purpose' => 'course_cart',
                'course_cart_order_id' => $orderId,
            ], JSON_THROW_ON_ERROR),
        ]);

        $commissionId = $service->recordSettledPurchase($buyer, 'course_cart_order', (string) $orderId, 1000, 'BDT');
        $this->assertNotNull($commissionId);

        return [$service, $buyer, $otherBuyer, $admin, (int) $orderId, (int) $commissionId, (int) $invoice->id];
    }

    private function createRefundFixture(): array
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'referral-income',
            'student-management',
            'payment-management',
            'fees-management',
            'commerce-cart',
        ]);

        $referrer = $this->createUserWithRole('student');
        $buyer = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $service = app(ReferralIncomeService::class);
        DB::table('referral_income_rules')->where('referrer_type', 'student')->update([
            'commission_percent' => 10,
            'minimum_payout' => 500,
            'is_active' => true,
            'updated_at' => now(),
        ]);
        $service->applyCode($buyer, $service->createOrGetCode($referrer));

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'REFUND-RACE-' . strtoupper(bin2hex(random_bytes(5))),
            'payer_name' => $buyer->name,
            'payer_email' => $buyer->email,
            'payer_phone' => '01700000000',
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'net_amount' => 1000,
            'paid_amount' => 1000,
            'currency' => 'BDT',
            'status' => 'paid',
            'due_date' => now()->toDateString(),
        ]);
        $orderId = DB::table('commerce_orders')->insertGetId([
            'order_number' => 'REFUND-RACE-ORDER-' . strtoupper(bin2hex(random_bytes(4))),
            'user_id' => $buyer->id,
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'currency' => 'BDT',
            'subtotal' => 1000,
            'discount_total' => 0,
            'grand_total' => 1000,
            'status' => 'completed',
            'payment_status' => 'paid',
            'billing_snapshot' => json_encode(['name' => $buyer->name, 'email' => $buyer->email]),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $commissionId = $service->recordSettledPurchase($buyer, 'commerce_order', (string) $orderId, 1000, 'BDT');
        $this->assertNotNull($commissionId);

        $paymentId = DB::table('payment_transactions')->insertGetId([
            'transaction_ref' => 'REFUND-RACE-TX-' . strtoupper(bin2hex(random_bytes(4))),
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'gateway_slug' => 'offline',
            'amount' => 1000,
            'currency' => 'BDT',
            'status' => 'settled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$service, $buyer, $admin, (int) $orderId, (int) $commissionId, (int) $paymentId];
    }

    private function createSecondaryMysqlConnection(string $primaryName): string
    {
        $primary = DB::connection($primaryName);
        $secondaryName = 'referral_race_secondary_' . bin2hex(random_bytes(3));
        $secondaryConfig = config('database.connections.' . $primaryName);
        $secondaryConfig['database'] = $primary->getDatabaseName();
        config(['database.connections.' . $secondaryName => $secondaryConfig]);
        DB::purge($secondaryName);
        DB::connection($secondaryName);

        return $secondaryName;
    }

}
