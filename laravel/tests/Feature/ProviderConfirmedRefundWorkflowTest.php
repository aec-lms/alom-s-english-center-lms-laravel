<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use App\Models\AuditLog;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentRefund;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\StudentManagement\Models\Student;
use Plugins\Modules\BranchManagement\Models\Branch;
use Plugins\Modules\PaymentManagement\Services\RefundOrchestrationService;
use Plugins\Modules\PaymentManagement\Services\RefundSettlementService;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use Plugins\Modules\GatewayPaypal\Services\PaypalGatewayService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProviderConfirmedRefundWorkflowTest extends TestCase
{
    public function test_refund_provider_registry_resolves_each_gateway_and_keeps_refunds_disabled_by_default(): void
    {
        $this->markSystemInstalled();
        $gateways = [
            'gateway-stripe' => \Plugins\Modules\GatewayStripe\Services\StripeRefundAdapter::class,
            'gateway-paypal' => \Plugins\Modules\GatewayPaypal\Services\PaypalRefundAdapter::class,
            'gateway-sslcommerz' => \Plugins\Modules\GatewaySslcommerz\Services\SslcommerzRefundAdapter::class,
        ];
        $this->activatePlugins(array_merge(['fees-management', 'payment-management'], array_keys($gateways)));
        $plugins = app(PluginManager::class);
        $registry = app(\Plugins\Modules\PaymentManagement\Services\RefundProviderRegistry::class);

        foreach ($gateways as $gateway => $adapterClass) {
            [, $transaction] = $this->createSettledPayment(
                $gateway,
                'REGISTRY-' . strtoupper(str_replace('-', '', $gateway)),
            );

            try {
                $registry->forTransaction($transaction);
                $this->fail("New {$gateway} refunds must remain disabled by default.");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('disabled', strtolower($exception->getMessage()));
            }

            $plugins->saveSettings($gateway, ['refunds_enabled' => '1']);
            $this->assertInstanceOf($adapterClass, $registry->forTransaction($transaction));
        }
    }

    public function test_payment_settlement_rejects_invoice_with_multiple_commerce_buyers(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'commerce-cart']);

        [$invoice, $transaction] = $this->createSettledPayment('gateway-stripe', 'pi_multi_buyer_settlement_001');
        $invoice->update(['paid_amount' => 0, 'status' => 'unpaid']);
        $transaction->update(['status' => 'initiated', 'external_reference' => null]);
        $buyerA = $this->createUserWithRole('student');
        $buyerB = $this->createUserWithRole('student');

        foreach ([$buyerA, $buyerB] as $index => $buyer) {
            DB::table('commerce_orders')->insert([
                'order_number' => 'MULTI-BUYER-' . $index . '-' . strtoupper(bin2hex(random_bytes(3))),
                'user_id' => $buyer->id,
                'invoice_id' => $invoice->id,
                'branch_id' => null,
                'currency' => 'BDT',
                'subtotal' => 500,
                'discount_total' => 0,
                'grand_total' => 500,
                'status' => 'pending',
                'payment_status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        try {
            app(PaymentSettlementService::class)->settleTransaction(
                $transaction,
                'pi_multi_buyer_settlement_001',
                ['provider' => 'test'],
            );
            $this->fail('An invoice tied to multiple commerce buyers must not settle.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('multiple buyers', strtolower($exception->getMessage()));
        }

        $this->assertSame('initiated', $transaction->fresh()->status);
        $this->assertSame(0.0, (float) $invoice->fresh()->paid_amount);
    }

    public function test_refund_request_rejects_commerce_buyer_mismatch_with_invoice_student(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-stripe', 'student-management', 'commerce-cart']);

        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_buyer_mismatch_123456',
            'refunds_enabled' => '1',
        ], ['secret_key']);

        [$invoice, $transaction] = $this->createSettledPayment('gateway-stripe', 'pi_buyer_mismatch_001');
        $invoiceStudent = $this->createUserWithRole('student');
        $differentBuyer = $this->createUserWithRole('student');
        $studentProfile = Student::query()->create([
            'user_id' => $invoiceStudent->id,
            'branch_id' => null,
            'student_code' => 'REFUND-BUYER-' . strtoupper(bin2hex(random_bytes(4))),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $invoice->update(['student_id' => $studentProfile->id]);

        DB::table('commerce_orders')->insert([
            'order_number' => 'BUYER-MISMATCH-' . strtoupper(bin2hex(random_bytes(3))),
            'user_id' => $differentBuyer->id,
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'currency' => 'BDT',
            'subtotal' => 1000,
            'discount_total' => 0,
            'grand_total' => 1000,
            'status' => 'completed',
            'payment_status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->createUserWithRole('admin');
        Http::fake();

        try {
            app(RefundOrchestrationService::class)->requestOnlineRefund(
                $transaction,
                100,
                'Buyer mismatch must block refund',
                (int) $admin->id,
                'stripe-buyer-mismatch-001',
            );
            $this->fail('A commerce order buyer that differs from the invoice student must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('buyer does not match', strtolower($exception->getMessage()));
        }

        $this->assertDatabaseCount('payment_refunds', 0);
        Http::assertNothingSent();
    }

    public function test_refund_request_rejects_invoice_and_transaction_branch_mismatch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-stripe', 'branch-management']);

        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_branch_mismatch_123456',
            'refunds_enabled' => '1',
        ], ['secret_key']);

        $branchA = Branch::query()->create([
            'code' => 'REFUND-A-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Refund Branch A',
            'is_active' => true,
        ]);
        $branchB = Branch::query()->create([
            'code' => 'REFUND-B-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Refund Branch B',
            'is_active' => true,
        ]);
        [$invoice, $transaction] = $this->createSettledPayment('gateway-stripe', 'pi_branch_mismatch_001');
        $invoice->update(['branch_id' => $branchA->id]);
        $transaction->update(['branch_id' => $branchB->id]);

        $admin = $this->createUserWithRole('admin');
        Http::fake();

        try {
            app(RefundOrchestrationService::class)->requestOnlineRefund(
                $transaction,
                100,
                'Branch mismatch must block refund',
                (int) $admin->id,
                'stripe-branch-mismatch-001',
            );
            $this->fail('A payment transaction outside its invoice branch must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('branch does not match', strtolower($exception->getMessage()));
        }

        $this->assertDatabaseCount('payment_refunds', 0);
        Http::assertNothingSent();
    }

    public function test_refund_request_can_be_rejected_by_a_different_authorized_user_without_provider_call(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-stripe']);

        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_refund_reject_123456',
            'refunds_enabled' => '1',
        ], ['secret_key']);

        [$invoice, $transaction] = $this->createSettledPayment('gateway-stripe', 'pi_refund_reject_001');
        $requester = $this->createUserWithRole('admin');
        $rejecter = $this->createUserWithRole('admin');
        $service = app(RefundOrchestrationService::class);
        Http::fake();

        $refund = $service->requestOnlineRefund(
            $transaction,
            200,
            'Reject test request',
            (int) $requester->id,
            'stripe-refund-reject-001',
        );

        try {
            $service->rejectRequest($refund, (int) $requester->id);
            $this->fail('The requester must not reject their own refund request.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $rejected = $service->rejectRequest($refund, (int) $rejecter->id);
        $this->assertSame('rejected', $rejected->status);
        $this->assertSame((int) $rejecter->id, (int) $rejected->rejected_by);
        $this->assertNotNull($rejected->rejected_at);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);
        Http::assertNothingSent();
    }

    public function test_stripe_refund_requires_a_second_approver_and_only_provider_success_changes_the_ledger(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-stripe']);

        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_refund_workflow_123456',
            'refunds_enabled' => '1',
        ], ['secret_key']);

        [$invoice, $transaction] = $this->createSettledPayment('gateway-stripe', 'pi_refund_workflow_001');
        $requester = $this->createUserWithRole('admin');
        $approver = $this->createUserWithRole('admin');
        $service = app(RefundOrchestrationService::class);

        Http::fake(function (ClientRequest $request) {
            $data = $request->data();

            return Http::response([
                'id' => 're_refund_workflow_001',
                'payment_intent' => 'pi_refund_workflow_001',
                'amount' => 25000,
                'currency' => 'bdt',
                'metadata' => [
                    'transaction_ref' => (string) ($data['metadata[transaction_ref]'] ?? data_get($data, 'metadata.transaction_ref', '')),
                    'refund_id' => (string) ($data['metadata[refund_id]'] ?? data_get($data, 'metadata.refund_id', '')),
                ],
                'status' => 'succeeded',
            ], 200);
        });

        $refund = $service->requestOnlineRefund(
            $transaction,
            250,
            'Approved partial fee refund',
            (int) $requester->id,
            'stripe-refund-idem-001',
        );
        $replay = $service->requestOnlineRefund(
            $transaction,
            250,
            'Approved partial fee refund',
            (int) $requester->id,
            'stripe-refund-idem-001',
        );
        $this->assertSame($refund->id, $replay->id);
        $this->assertSame('requested', $refund->status);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);
        Http::assertNothingSent();

        try {
            $service->approveAndSubmit($refund, (int) $requester->id);
            $this->fail('The requester must not approve their own refund.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Http::assertNothingSent();

        try {
            $service->requestOnlineRefund(
                $transaction,
                251,
                'Different amount using same key',
                (int) $requester->id,
                'stripe-refund-idem-001',
            );
            $this->fail('A changed request must not reuse the same local idempotency key.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $confirmed = $service->approveAndSubmit($refund, (int) $approver->id);

        $this->assertSame('succeeded', $confirmed->status);
        $this->assertSame('re_refund_workflow_001', $confirmed->provider_refund_reference);
        $this->assertSame((int) $approver->id, (int) $confirmed->approved_by);
        $this->assertNotNull($confirmed->ledger_applied_at);
        $this->assertSame(750.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertSame('settled', $transaction->fresh()->status);

        // A duplicate provider confirmation must not apply the ledger twice.
        $replayed = app(RefundSettlementService::class)->applyProviderConfirmedRefund($confirmed);
        $this->assertSame($confirmed->id, $replayed->id);
        $this->assertSame(750.0, (float) $invoice->fresh()->paid_amount);

        $this->assertDatabaseHas('payment_refunds', [
            'id' => $refund->id,
            'status' => 'succeeded',
            'provider_refund_reference' => 're_refund_workflow_001',
        ]);
        Http::assertSentCount(1);
    }

    public function test_stripe_refund_rejects_a_different_local_refund_identity_without_ledger_mutation(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-stripe']);

        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_refund_identity_123456',
            'refunds_enabled' => '1',
        ], ['secret_key']);

        [$invoice, $transaction] = $this->createSettledPayment('gateway-stripe', 'pi_refund_identity_001');
        $requester = $this->createUserWithRole('admin');
        $approver = $this->createUserWithRole('admin');
        $service = app(RefundOrchestrationService::class);

        Http::fake(function (ClientRequest $request) {
            $data = $request->data();

            return Http::response([
                'id' => 're_refund_identity_mismatch',
                'payment_intent' => 'pi_refund_identity_001',
                'amount' => 10000,
                'currency' => 'bdt',
                'metadata' => [
                    'transaction_ref' => 'SOME-OTHER-TRANSACTION',
                    'refund_id' => (string) ($data['metadata[refund_id]'] ?? data_get($data, 'metadata.refund_id', '')),
                ],
                'status' => 'succeeded',
            ], 200);
        });

        $refund = $service->requestOnlineRefund(
            $transaction,
            100,
            'Stripe identity mismatch',
            (int) $requester->id,
            'stripe-refund-identity-mismatch',
        );
        $review = $service->approveAndSubmit($refund, (int) $approver->id);

        $this->assertSame('requires_review', $review->status);
        $this->assertSame('provider_response_mismatch', $review->provider_status);
        $this->assertNull($review->ledger_applied_at);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('settled', $transaction->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_paypal_refund_rejects_a_different_local_refund_identity_without_ledger_mutation(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-paypal']);

        app(PluginManager::class)->saveSettings('gateway-paypal', [
            'client_id' => 'paypal-refund-identity-client',
            'client_secret' => 'paypal-refund-identity-secret',
            'sandbox_mode' => '1',
            'refunds_enabled' => '1',
        ], ['client_secret']);

        [$invoice, $transaction] = $this->createSettledPayment(
            'gateway-paypal',
            'CAPTURE-REFUND-IDENTITY-001',
            ['paypal_order_id' => 'ORDER-REFUND-IDENTITY-001'],
        );
        $requester = $this->createUserWithRole('admin');
        $approver = $this->createUserWithRole('admin');
        $service = app(RefundOrchestrationService::class);

        Http::fake(function (ClientRequest $request) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'paypal-refund-identity-token'], 200);
            }

            return Http::response([
                'id' => 'PAYPAL-REFUND-IDENTITY-MISMATCH',
                'invoice_id' => 'REFUND-OTHER-TRANSACTION',
                'custom_id' => 'refund-999999',
                'status' => 'COMPLETED',
                'amount' => ['value' => '100.00', 'currency_code' => 'BDT'],
                'links' => [[
                    'rel' => 'up',
                    'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE-REFUND-IDENTITY-001',
                ]],
            ], 201);
        });

        $refund = $service->requestOnlineRefund(
            $transaction,
            100,
            'PayPal identity mismatch',
            (int) $requester->id,
            'paypal-refund-identity-mismatch',
        );
        $review = $service->approveAndSubmit($refund, (int) $approver->id);

        $this->assertSame('requires_review', $review->status);
        $this->assertSame('provider_response_mismatch', $review->provider_status);
        $this->assertNull($review->ledger_applied_at);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('settled', $transaction->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_online_refunds_are_disabled_by_default(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-stripe']);

        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_refund_disabled_123456',
        ], ['secret_key']);

        [$invoice, $transaction] = $this->createSettledPayment('gateway-stripe', 'pi_refund_disabled_001');
        $admin = $this->createUserWithRole('admin');
        Http::fake();

        try {
            app(RefundOrchestrationService::class)->requestOnlineRefund(
                $transaction,
                100,
                'Refunds should remain disabled by default',
                (int) $admin->id,
                'stripe-refund-disabled-001',
            );
            $this->fail('Online provider refunds must be disabled unless explicitly enabled after sandbox verification.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('disabled', strtolower($exception->getMessage()));
        }

        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);
        Http::assertNothingSent();
    }

    public function test_refund_request_rolls_back_when_required_audit_write_fails(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-stripe']);
        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_refund_audit_rollback_123456',
            'refunds_enabled' => '1',
        ], ['secret_key']);
        [, $transaction] = $this->createSettledPayment('gateway-stripe', 'pi_refund_audit_rollback');
        $requester = $this->createUserWithRole('admin');

        AuditLog::creating(static function (AuditLog $log): void {
            if ($log->action === 'payment.refund_requested') {
                throw new \RuntimeException('forced refund audit failure');
            }
        });

        try {
            try {
                app(RefundOrchestrationService::class)->requestOnlineRefund(
                    $transaction,
                    100,
                    'Audit failure rollback test',
                    (int) $requester->id,
                    'refund-audit-rollback-001',
                );
                $this->fail('Refund request must roll back when its required audit event cannot be stored.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('forced refund audit failure', $exception->getMessage());
            }
        } finally {
            AuditLog::flushEventListeners();
        }

        $this->assertSame(0, PaymentRefund::query()->where('payment_transaction_id', $transaction->id)->count());
        $this->assertSame('settled', $transaction->fresh()->status);
    }

    public function test_offline_refund_keeps_existing_ledger_behavior_and_is_audited_once(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management']);

        [$invoice, $transaction] = $this->createSettledPayment('offline', 'OFFLINE-RECEIPT-001');
        $admin = $this->createUserWithRole('admin');

        $refund = app(RefundSettlementService::class)->recordOfflineRefund(
            $transaction,
            200,
            'Cash refund with signed receipt',
            (int) $admin->id,
            'OFFLINE-RECEIPT-REFUND-001',
        );

        $this->assertSame('succeeded', $refund->status);
        $this->assertSame('offline', $refund->gateway_slug);
        $this->assertNotNull($refund->ledger_applied_at);
        $this->assertSame(800.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertSame('settled', $transaction->fresh()->status);
    }

    public function test_full_refund_marks_transaction_refunded_only_after_provider_success(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-stripe']);

        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_full_refund_123456',
            'refunds_enabled' => '1',
        ], ['secret_key']);

        [$invoice, $transaction] = $this->createSettledPayment('gateway-stripe', 'pi_full_refund_001');
        $requester = $this->createUserWithRole('admin');
        $approver = $this->createUserWithRole('admin');
        $service = app(RefundOrchestrationService::class);

        Http::fake(function (ClientRequest $request) {
            $data = $request->data();

            return Http::response([
                'id' => 're_full_refund_001',
                'payment_intent' => 'pi_full_refund_001',
                'amount' => 100000,
                'currency' => 'bdt',
                'metadata' => [
                    'transaction_ref' => (string) ($data['metadata[transaction_ref]'] ?? data_get($data, 'metadata.transaction_ref', '')),
                    'refund_id' => (string) ($data['metadata[refund_id]'] ?? data_get($data, 'metadata.refund_id', '')),
                ],
                'status' => 'succeeded',
            ], 200);
        });

        $refund = $service->requestOnlineRefund(
            $transaction,
            1000,
            'Full payment refund',
            (int) $requester->id,
            'stripe-full-refund-001',
        );
        $confirmed = $service->approveAndSubmit($refund, (int) $approver->id);

        $this->assertSame('succeeded', $confirmed->status);
        $this->assertSame('refunded', $transaction->fresh()->status);
        $this->assertSame(0.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertNotNull($confirmed->ledger_applied_at);
    }

    public function test_failed_malformed_and_unknown_stripe_responses_never_mutate_invoice_ledger(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-stripe']);

        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_refund_failures_123456',
            'refunds_enabled' => '1',
        ], ['secret_key']);

        [$invoice, $transaction] = $this->createSettledPayment('gateway-stripe', 'pi_refund_failure_001');
        $requester = $this->createUserWithRole('admin');
        $approver = $this->createUserWithRole('admin');
        $service = app(RefundOrchestrationService::class);

        $stripeResponse = 0;
        Http::fake(function (ClientRequest $request) use (&$stripeResponse) {
            $data = $request->data();
            $stripeResponse++;

            if ($stripeResponse === 3) {
                return Http::response(['error' => ['message' => 'temporary provider outage']], 500);
            }

            return Http::response([
                'id' => $stripeResponse === 1 ? 're_refund_failed_001' : 're_refund_mismatch_001',
                'payment_intent' => 'pi_refund_failure_001',
                'amount' => $stripeResponse === 1 ? 20000 : 19999,
                'currency' => 'bdt',
                'metadata' => [
                    'transaction_ref' => (string) ($data['metadata[transaction_ref]'] ?? data_get($data, 'metadata.transaction_ref', '')),
                    'refund_id' => (string) ($data['metadata[refund_id]'] ?? data_get($data, 'metadata.refund_id', '')),
                ],
                'status' => $stripeResponse === 1 ? 'failed' : 'succeeded',
            ], 200);
        });

        $failedRequest = $service->requestOnlineRefund(
            $transaction,
            200,
            'Provider declined refund',
            (int) $requester->id,
            'stripe-refund-failed-001',
        );
        $failed = $service->approveAndSubmit($failedRequest, (int) $approver->id);
        $this->assertSame('failed', $failed->status);
        $this->assertNotNull($failed->failed_at);
        $this->assertNull($failed->ledger_applied_at);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);

        $mismatchRequest = $service->requestOnlineRefund(
            $transaction,
            200,
            'Malformed amount response',
            (int) $requester->id,
            'stripe-refund-mismatch-001',
        );
        $mismatch = $service->approveAndSubmit($mismatchRequest, (int) $approver->id);
        $this->assertSame('requires_review', $mismatch->status);
        $this->assertSame('provider_response_mismatch', $mismatch->provider_status);
        $this->assertNull($mismatch->ledger_applied_at);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);

        $unknownRequest = $service->requestOnlineRefund(
            $transaction,
            200,
            'Unknown provider submission outcome',
            (int) $requester->id,
            'stripe-refund-unknown-001',
        );
        $unknown = $service->approveAndSubmit($unknownRequest, (int) $approver->id);
        $this->assertSame('requires_review', $unknown->status);
        $this->assertNull($unknown->provider_refund_reference);
        $this->assertNull($unknown->ledger_applied_at);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);
        Http::assertSentCount(3);
    }

    public function test_stripe_refund_rejects_provider_metadata_for_another_local_refund(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-stripe']);

        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_refund_identity_123456',
            'refunds_enabled' => '1',
        ], ['secret_key']);

        [$invoice, $transaction] = $this->createSettledPayment('gateway-stripe', 'pi_refund_identity_001');
        $requester = $this->createUserWithRole('admin');
        $approver = $this->createUserWithRole('admin');
        Http::fake(function (ClientRequest $request) {
            return Http::response([
                'id' => 're_refund_identity_wrong_local_row',
                'payment_intent' => 'pi_refund_identity_001',
                'amount' => 10000,
                'currency' => 'bdt',
                'metadata' => [
                    'transaction_ref' => 'A-DIFFERENT-TRANSACTION',
                    'refund_id' => '999999',
                ],
                'status' => 'succeeded',
            ], 200);
        });

        $service = app(RefundOrchestrationService::class);
        $refund = $service->requestOnlineRefund(
            $transaction,
            100,
            'Provider returned another refund identity',
            (int) $requester->id,
            'stripe-refund-identity-mismatch-001',
        );
        $review = $service->approveAndSubmit($refund, (int) $approver->id);

        $this->assertSame('requires_review', $review->status);
        $this->assertSame('provider_response_mismatch', $review->provider_status);
        $this->assertSame('re_refund_identity_wrong_local_row', $review->provider_refund_reference);
        $this->assertNull($review->ledger_applied_at);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('settled', $transaction->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_pending_stripe_refund_keeps_invoice_unchanged_and_reserves_amount_until_provider_query_confirms_success(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-stripe']);

        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_refund_pending_123456',
            'refunds_enabled' => '1',
        ], ['secret_key']);

        [$invoice, $transaction] = $this->createSettledPayment('gateway-stripe', 'pi_refund_pending_001');
        $requester = $this->createUserWithRole('admin');
        $approver = $this->createUserWithRole('admin');
        $service = app(RefundOrchestrationService::class);

        $localRefundId = null;
        Http::fake(function (ClientRequest $request) use ($transaction, &$localRefundId) {
            $data = $request->data();
            $responseTransactionRef = (string) ($data['metadata[transaction_ref]'] ?? data_get($data, 'metadata.transaction_ref', ''));
            $responseRefundId = (string) ($data['metadata[refund_id]'] ?? data_get($data, 'metadata.refund_id', ''));
            $metadata = [
                'transaction_ref' => $responseTransactionRef !== '' ? $responseTransactionRef : (string) $transaction->transaction_ref,
                'refund_id' => $responseRefundId !== '' ? $responseRefundId : (string) ($localRefundId ?? ''),
            ];

            return Http::response([
                'id' => 're_refund_pending_001',
                'payment_intent' => 'pi_refund_pending_001',
                'amount' => 40000,
                'currency' => 'bdt',
                'metadata' => $metadata,
                'status' => $request->method() === 'POST' ? 'pending' : 'succeeded',
            ], 200);
        });

        $refund = $service->requestOnlineRefund(
            $transaction,
            400,
            'Pending bank refund',
            (int) $requester->id,
            'stripe-refund-pending-001',
        );
        $localRefundId = (string) $refund->id;
        $pending = $service->approveAndSubmit($refund, (int) $approver->id);

        $this->assertSame('pending', $pending->status);
        $this->assertNull($pending->ledger_applied_at);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('settled', $transaction->fresh()->status);

        try {
            $service->requestOnlineRefund(
                $transaction,
                600.01,
                'Would exceed reserved refundable balance by one cent',
                (int) $requester->id,
                'stripe-refund-pending-002',
            );
            $this->fail('Pending provider refunds must reserve their amount against over-refund.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        // Disabling new refunds must not strand an already-submitted refund.
        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_refund_pending_123456',
            'refunds_enabled' => '0',
        ], ['secret_key']);

        $confirmed = $service->reconcile($pending, (int) $approver->id);
        $this->assertSame('succeeded', $confirmed->status, 'Provider-confirmed ledger settlement failed: ' . json_encode($confirmed->metadata, JSON_THROW_ON_ERROR));
        $this->assertNotNull($confirmed->ledger_applied_at);
        $this->assertSame(600.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertSame('settled', $transaction->fresh()->status);
        $this->assertNotNull($confirmed->reconciled_at);
        Http::assertSentCount(2);
    }

    public function test_sslcommerz_refund_is_pending_until_refund_query_reports_refunded(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-sslcommerz']);

        app(PluginManager::class)->saveSettings('gateway-sslcommerz', [
            'store_id' => 'aec-refund-sandbox',
            'store_password' => 'ssl-refund-sandbox-secret',
            'sandbox_mode' => '1',
            'refunds_enabled' => '1',
        ], ['store_password']);

        [$invoice, $transaction] = $this->createSettledPayment(
            'gateway-sslcommerz',
            'SSL-VALIDATION-REF-001',
            ['bank_transaction_id' => 'BANK-REFUND-001'],
        );
        $requester = $this->createUserWithRole('admin');
        $approver = $this->createUserWithRole('admin');
        $service = app(RefundOrchestrationService::class);

        Http::fake(function (ClientRequest $request) use ($transaction) {
            $data = $request->data();
            if (isset($data['refund_trans_id'])) {
                return Http::response([
                    'APIConnect' => 'DONE',
                    'bank_tran_id' => 'BANK-REFUND-001',
                    'trans_id' => $transaction->transaction_ref,
                    'refund_ref_id' => 'SSL-REFUND-REF-001',
                    'status' => 'success',
                    'errorReason' => '',
                ], 200);
            }

            return Http::response([
                'APIConnect' => 'DONE',
                'bank_tran_id' => 'BANK-REFUND-001',
                'tran_id' => $transaction->transaction_ref,
                'refund_ref_id' => 'SSL-REFUND-REF-001',
                'status' => 'refunded',
            ], 200);
        });

        $refund = $service->requestOnlineRefund(
            $transaction,
            100,
            'SSLCOMMERZ refund request',
            (int) $requester->id,
            'ssl-refund-idem-001',
        );
        $pending = $service->approveAndSubmit($refund, (int) $approver->id);

        $this->assertSame('pending', $pending->status);
        $this->assertSame('SSL-REFUND-REF-001', $pending->provider_refund_reference);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);

        $confirmed = $service->reconcile($pending, (int) $approver->id);
        $this->assertSame('succeeded', $confirmed->status);
        $this->assertNotNull($confirmed->ledger_applied_at);
        $this->assertSame(900.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('partial', $invoice->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_sslcommerz_initiation_rejects_a_different_merchant_transaction_id(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-sslcommerz']);

        app(PluginManager::class)->saveSettings('gateway-sslcommerz', [
            'store_id' => 'aec-refund-sandbox',
            'store_password' => 'ssl-refund-sandbox-secret',
            'sandbox_mode' => '1',
            'refunds_enabled' => '1',
        ], ['store_password']);

        [$invoice, $transaction] = $this->createSettledPayment(
            'gateway-sslcommerz',
            'SSL-VALIDATION-REF-MISMATCH-001',
            ['bank_transaction_id' => 'BANK-REFUND-MISMATCH-001'],
        );
        $requester = $this->createUserWithRole('admin');
        $approver = $this->createUserWithRole('admin');

        Http::fake(function (ClientRequest $request) {
            if (isset($request->data()['refund_trans_id'])) {
                return Http::response([
                    'APIConnect' => 'DONE',
                    'bank_tran_id' => 'BANK-REFUND-MISMATCH-001',
                    'trans_id' => 'SOME-OTHER-MERCHANT-TRANSACTION',
                    'refund_ref_id' => 'SSL-REFUND-MISMATCH-001',
                    'status' => 'success',
                    'errorReason' => '',
                ], 200);
            }

            return Http::response([], 500);
        });

        $service = app(RefundOrchestrationService::class);
        $refund = $service->requestOnlineRefund(
            $transaction,
            100,
            'SSLCOMMERZ mismatched transaction response',
            (int) $requester->id,
            'ssl-refund-mismatch-idem-001',
        );
        $review = $service->approveAndSubmit($refund, (int) $approver->id);

        $this->assertSame('requires_review', $review->status);
        $this->assertSame('provider_response_mismatch', $review->provider_status);
        $this->assertNull($review->provider_refund_reference);
        $this->assertNull($review->ledger_applied_at);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('settled', $transaction->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_paypal_refund_for_a_different_capture_is_rejected_without_ledger_mutation(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-paypal']);

        app(PluginManager::class)->saveSettings('gateway-paypal', [
            'client_id' => 'paypal-capture-mismatch-client',
            'client_secret' => 'paypal-capture-mismatch-secret',
            'sandbox_mode' => '1',
            'refunds_enabled' => '1',
        ], ['client_secret']);

        [$invoice, $transaction] = $this->createSettledPayment(
            'gateway-paypal',
            'CAPTURE-ORIGINAL-001',
        );
        $requester = $this->createUserWithRole('admin');
        $approver = $this->createUserWithRole('admin');
        $service = app(RefundOrchestrationService::class);

        Http::fake(function (ClientRequest $request) use ($transaction) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'paypal-capture-mismatch-token'], 200);
            }

            return Http::response([
                'id' => 'PAYPAL-REFUND-WRONG-CAPTURE',
                'status' => 'COMPLETED',
                'amount' => ['value' => '100.00', 'currency_code' => 'BDT'],
                'links' => [[
                    'rel' => 'up',
                    'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE-OTHER-002',
                ]],
            ], 201);
        });

        $refund = $service->requestOnlineRefund(
            $transaction,
            100,
            'Capture mismatch test',
            (int) $requester->id,
            'paypal-capture-mismatch-001',
        );
        $review = $service->approveAndSubmit($refund, (int) $approver->id);

        $this->assertSame('requires_review', $review->status);
        $this->assertSame('provider_response_mismatch', $review->provider_status);
        $this->assertSame('PAYPAL-REFUND-WRONG-CAPTURE', $review->provider_refund_reference);
        $this->assertNull($review->ledger_applied_at);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('settled', $transaction->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_paypal_multi_capture_order_is_rejected_instead_of_settling_only_the_first_capture(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-paypal']);

        app(PluginManager::class)->saveSettings('gateway-paypal', [
            'client_id' => 'paypal-multi-capture-client',
            'client_secret' => 'paypal-multi-capture-secret',
            'sandbox_mode' => '1',
        ], ['client_secret']);

        [$invoice, $transaction] = $this->createSettledPayment('gateway-paypal', 'ORDER-MULTI-CAPTURE-001');
        $invoice->update(['paid_amount' => 0, 'status' => 'unpaid']);
        $transaction->update([
            'status' => 'initiated',
            'external_reference' => 'ORDER-MULTI-CAPTURE-001',
            'metadata' => ['paypal_order_id' => 'ORDER-MULTI-CAPTURE-001'],
        ]);

        Http::fake(function (ClientRequest $request) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'paypal-multi-capture-token'], 200);
            }

            return Http::response([
                'id' => 'ORDER-MULTI-CAPTURE-001',
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'payments' => [
                        'captures' => [
                            ['id' => 'CAPTURE-MULTI-001', 'status' => 'COMPLETED', 'amount' => ['value' => '500.00', 'currency_code' => 'BDT']],
                            ['id' => 'CAPTURE-MULTI-002', 'status' => 'COMPLETED', 'amount' => ['value' => '500.00', 'currency_code' => 'BDT']],
                        ],
                    ],
                ]],
            ], 201);
        });

        try {
            app(PaypalGatewayService::class)->captureOrder((string) $transaction->transaction_ref);
            $this->fail('Multi-capture PayPal orders must not settle against only the first capture.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('multi-capture', strtolower($exception->getMessage()));
        }

        $this->assertSame('initiated', $transaction->fresh()->status);
        $this->assertSame(0.0, (float) $invoice->fresh()->paid_amount);
        Http::assertSentCount(2);
    }

    public function test_paypal_refund_is_bound_to_the_original_capture_and_confirmed_before_ledger_update(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-paypal']);

        app(PluginManager::class)->saveSettings('gateway-paypal', [
            'client_id' => 'paypal-refund-client',
            'client_secret' => 'paypal-refund-secret',
            'sandbox_mode' => '1',
            'refunds_enabled' => '1',
        ], ['client_secret']);

        [$invoice, $transaction] = $this->createSettledPayment(
            'gateway-paypal',
            'CAPTURE-REFUND-001',
            ['paypal_order_id' => 'ORDER-REFUND-001'],
        );
        $requester = $this->createUserWithRole('admin');
        $approver = $this->createUserWithRole('admin');
        $service = app(RefundOrchestrationService::class);

        Http::fake(function (ClientRequest $request) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/v1/oauth2/token')) {
                return Http::response([
                    'access_token' => 'paypal-refund-access-token',
                    'token_type' => 'Bearer',
                    'expires_in' => 300,
                ], 200);
            }

            $data = $request->data();

            return Http::response([
                'id' => 'PAYPAL-REFUND-001',
                'invoice_id' => $data['invoice_id'] ?? '',
                'custom_id' => $data['custom_id'] ?? '',
                'status' => 'COMPLETED',
                'amount' => ['value' => '300.00', 'currency_code' => 'BDT'],
                'links' => [
                    [
                        'rel' => 'up',
                        'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE-REFUND-001',
                    ],
                ],
            ], 201);
        });

        $refund = $service->requestOnlineRefund(
            $transaction,
            300,
            'PayPal partial refund',
            (int) $requester->id,
            'paypal-refund-idem-001',
        );
        $confirmed = $service->approveAndSubmit($refund, (int) $approver->id);

        $this->assertSame('succeeded', $confirmed->status);
        $this->assertSame('PAYPAL-REFUND-001', $confirmed->provider_refund_reference);
        $this->assertSame(700.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertNotNull($confirmed->ledger_applied_at);
        Http::assertSentCount(2);
    }

    public function test_applied_refunds_reduce_invoice_capacity_without_being_reserved_twice(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-stripe']);

        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_invoice_refund_capacity_123456',
            'refunds_enabled' => '1',
        ], ['secret_key']);

        [$invoice, $firstTransaction] = $this->createSettledPayment('gateway-stripe', 'pi_invoice_refund_001');
        $secondTransaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'REFUND-TXN-' . strtoupper(bin2hex(random_bytes(5))),
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'gateway_slug' => 'gateway-stripe',
            'amount' => 1000,
            'currency' => 'BDT',
            'status' => 'settled',
            'external_reference' => 'pi_invoice_refund_002',
            'metadata' => [],
            'settled_at' => now(),
        ]);
        $requester = $this->createUserWithRole('admin');
        $firstApprover = $this->createUserWithRole('admin');
        $secondApprover = $this->createUserWithRole('admin');
        $service = app(RefundOrchestrationService::class);

        $invoiceRefundResponse = 0;
        Http::fake(function (ClientRequest $request) use (&$invoiceRefundResponse) {
            $data = $request->data();
            $invoiceRefundResponse++;

            return Http::response([
                'id' => $invoiceRefundResponse === 1 ? 're_invoice_refund_001' : 're_invoice_refund_002',
                'payment_intent' => $invoiceRefundResponse === 1 ? 'pi_invoice_refund_001' : 'pi_invoice_refund_002',
                'amount' => $invoiceRefundResponse === 1 ? 30000 : 70000,
                'currency' => 'bdt',
                'metadata' => [
                    'transaction_ref' => (string) ($data['metadata[transaction_ref]'] ?? data_get($data, 'metadata.transaction_ref', '')),
                    'refund_id' => (string) ($data['metadata[refund_id]'] ?? data_get($data, 'metadata.refund_id', '')),
                ],
                'status' => 'succeeded',
            ], 200);
        });

        $first = $service->requestOnlineRefund(
            $firstTransaction,
            300,
            'First partial invoice refund',
            (int) $requester->id,
            'invoice-refund-cap-001',
        );
        $service->approveAndSubmit($first, (int) $firstApprover->id);
        $this->assertSame(700.0, (float) $invoice->fresh()->paid_amount);

        // The first refund is already applied to paid_amount; it must not be
        // subtracted again from the remaining invoice refund capacity.
        $second = $service->requestOnlineRefund(
            $secondTransaction,
            700,
            'Second transaction refund',
            (int) $requester->id,
            'invoice-refund-cap-002',
        );
        $confirmed = $service->approveAndSubmit($second, (int) $secondApprover->id);

        $this->assertSame('succeeded', $confirmed->status);
        $this->assertSame(0.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame('settled', $firstTransaction->fresh()->status);
        $this->assertSame('settled', $secondTransaction->fresh()->status);
        $this->assertSame(2, PaymentRefund::query()->where('status', 'succeeded')->count());
    }

    public function test_online_refund_for_gift_order_is_blocked_before_provider_submission(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-stripe', 'commerce-cart']);

        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_gift_refund_123456',
            'refunds_enabled' => '1',
        ], ['secret_key']);

        [$invoice, $transaction] = $this->createSettledPayment('gateway-stripe', 'pi_gift_refund_001');
        $buyer = $this->createUserWithRole('student');
        $orderId = DB::table('commerce_orders')->insertGetId([
            'order_number' => 'GIFT-ONLINE-REFUND-' . strtoupper(bin2hex(random_bytes(3))),
            'user_id' => $buyer->id,
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'currency' => 'BDT',
            'subtotal' => 1000,
            'discount_total' => 0,
            'grand_total' => 1000,
            'status' => 'completed',
            'payment_status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('commerce_order_items')->insert([
            'order_id' => $orderId,
            'item_type' => 'gift_voucher',
            'item_id' => 987654,
            'title_snapshot' => 'Gift Voucher',
            'quantity' => 1,
            'unit_price' => 1000,
            'line_total' => 1000,
            'metadata' => json_encode(['course_gift_voucher_id' => 987654], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->createUserWithRole('admin');
        Http::fake();

        try {
            app(RefundOrchestrationService::class)->requestOnlineRefund(
                $transaction,
                100,
                'Gift refund must remain blocked',
                (int) $admin->id,
                'stripe-gift-refund-001',
            );
            $this->fail('Online refunds for gift orders must fail closed before provider submission.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('gift vouchers', strtolower($exception->getMessage()));
        }

        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);
        Http::assertNothingSent();
    }

    public function test_stripe_reconciliation_rejects_provider_refund_id_different_from_requested_reference(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-stripe']);
        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_reconcile_identity_123456',
            'refunds_enabled' => '1',
        ], ['secret_key']);

        [$invoice, $transaction] = $this->createSettledPayment('gateway-stripe', 'pi_reconcile_identity_001');
        $refund = new PaymentRefund();
        $refund->forceFill([
            'id' => 91001,
            'amount' => 100,
            'provider_refund_reference' => 're_expected_reconcile_001',
        ]);

        Http::fake([
            'https://api.stripe.com/v1/refunds/*' => Http::response([
                'id' => 're_wrong_reconcile_001',
                'payment_intent' => 'pi_reconcile_identity_001',
                'amount' => 10000,
                'currency' => 'bdt',
                'metadata' => [
                    'transaction_ref' => $transaction->transaction_ref,
                    'refund_id' => '91001',
                ],
                'status' => 'succeeded',
            ], 200),
        ]);

        $result = app(\Plugins\Modules\GatewayStripe\Services\StripeRefundAdapter::class)
            ->reconcile($transaction, $refund);

        $this->assertSame('requires_review', $result->status);
        $this->assertSame('provider_response_mismatch', $result->providerStatus);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('settled', $transaction->fresh()->status);
    }

    public function test_paypal_reconciliation_rejects_provider_refund_id_different_from_requested_reference(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-paypal']);
        app(PluginManager::class)->saveSettings('gateway-paypal', [
            'client_id' => 'paypal-reconcile-identity-client',
            'client_secret' => 'paypal-reconcile-identity-secret',
            'sandbox_mode' => '1',
            'refunds_enabled' => '1',
        ], ['client_secret']);

        [$invoice, $transaction] = $this->createSettledPayment(
            'gateway-paypal',
            'PAYPAL-RECONCILE-CAPTURE-001',
        );
        $refund = new PaymentRefund();
        $refund->forceFill([
            'id' => 91002,
            'amount' => 100,
            'provider_refund_reference' => 'EXPECTED-PAYPAL-REFUND-001',
        ]);

        Http::fake(function (ClientRequest $request) use ($transaction): \Illuminate\Http\Client\Response {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'paypal-reconcile-identity-token'], 200);
            }

            return Http::response([
                'id' => 'WRONG-PAYPAL-REFUND-001',
                'invoice_id' => $transaction->transaction_ref,
                'custom_id' => 'refund-91002',
                'status' => 'COMPLETED',
                'amount' => ['value' => '100.00', 'currency_code' => 'BDT'],
                'links' => [[
                    'rel' => 'up',
                    'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/PAYPAL-RECONCILE-CAPTURE-001',
                ]],
            ], 200);
        });

        $result = app(\Plugins\Modules\GatewayPaypal\Services\PaypalRefundAdapter::class)
            ->reconcile($transaction, $refund);

        $this->assertSame('requires_review', $result->status);
        $this->assertSame('provider_response_mismatch', $result->providerStatus);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('settled', $transaction->fresh()->status);
    }

    private function createSettledPayment(
        string $gateway,
        string $externalReference,
        array $metadata = [],
    ): array {
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'REFUND-' . strtoupper(bin2hex(random_bytes(5))),
            'payer_name' => 'Refund Workflow Buyer',
            'payer_email' => 'refund-buyer-' . strtolower(bin2hex(random_bytes(3))) . '@aec.test',
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'net_amount' => 1000,
            'paid_amount' => 1000,
            'currency' => 'BDT',
            'status' => 'paid',
        ]);

        $transaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'REFUND-TXN-' . strtoupper(bin2hex(random_bytes(5))),
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'gateway_slug' => $gateway,
            'amount' => 1000,
            'currency' => 'BDT',
            'status' => 'settled',
            'external_reference' => $externalReference,
            'metadata' => $metadata,
            'settled_at' => now(),
        ]);

        return [$invoice, $transaction];
    }

    public function test_paypal_refund_rejects_mismatched_invoice_and_custom_refund_ids(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-paypal']);

        app(PluginManager::class)->saveSettings('gateway-paypal', [
            'client_id' => 'paypal-refund-identity-client',
            'client_secret' => 'paypal-refund-identity-secret',
            'sandbox_mode' => '1',
            'refunds_enabled' => '1',
        ], ['client_secret']);

        [$invoice, $transaction] = $this->createSettledPayment(
            'gateway-paypal',
            'PAYPAL-IDENTITY-CAPTURE-001',
        );
        $requester = $this->createUserWithRole('admin');
        $approver = $this->createUserWithRole('admin');

        Http::fake(function (ClientRequest $request) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'paypal-refund-identity-token'], 200);
            }

            return Http::response([
                'id' => 'PAYPAL-REFUND-IDENTITY-WRONG',
                'status' => 'COMPLETED',
                'amount' => ['value' => '100.00', 'currency_code' => 'BDT'],
                'invoice_id' => 'A-DIFFERENT-LOCAL-TRANSACTION',
                'custom_id' => 'refund-999999',
                'links' => [[
                    'rel' => 'up',
                    'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/PAYPAL-IDENTITY-CAPTURE-001',
                ]],
            ], 201);
        });

        $service = app(RefundOrchestrationService::class);
        $refund = $service->requestOnlineRefund(
            $transaction,
            100,
            'PayPal returned a different local refund identity',
            (int) $requester->id,
            'paypal-refund-identity-mismatch-001',
        );
        $review = $service->approveAndSubmit($refund, (int) $approver->id);

        $this->assertSame('requires_review', $review->status);
        $this->assertSame('provider_response_mismatch', $review->provider_status);
        $this->assertSame('PAYPAL-REFUND-IDENTITY-WRONG', $review->provider_refund_reference);
        $this->assertNull($review->ledger_applied_at);
        $this->assertSame(1000.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('settled', $transaction->fresh()->status);
        Http::assertSentCount(2);
    }


    public function test_refund_approval_rejects_changed_transaction_and_gateway_bindings(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-stripe']);

        app(PluginManager::class)->saveSettings('gateway-stripe', [
            'secret_key' => 'sk_test_refund_binding_123456',
            'refunds_enabled' => '1',
        ], ['secret_key']);
        Http::fake();

        [$invoiceA, $transactionA] = $this->createSettledPayment('gateway-stripe', 'pi_refund_binding_a');
        [$invoiceB, $transactionB] = $this->createSettledPayment('gateway-stripe', 'pi_refund_binding_b');
        $requester = $this->createUserWithRole('admin');
        $approver = $this->createUserWithRole('admin');
        $service = app(RefundOrchestrationService::class);

        $transactionBinding = $service->requestOnlineRefund(
            $transactionA, 100, 'Verify refund transaction ownership', (int) $requester->id, 'refund-binding-tx-001'
        );
        DB::table('payment_refunds')->where('id', $transactionBinding->id)->update([
            'payment_transaction_id' => $transactionB->id,
        ]);

        try {
            $service->approveAndSubmit($transactionBinding, (int) $approver->id);
            $this->fail('A refund moved to another payment transaction must not be submitted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('payment_refunds', [
            'id' => $transactionBinding->id,
            'status' => 'requested',
            'payment_transaction_id' => $transactionB->id,
        ]);

        $gatewayBinding = $service->requestOnlineRefund(
            $transactionB, 100, 'Verify refund gateway ownership', (int) $requester->id, 'refund-binding-gateway-001'
        );
        DB::table('payment_refunds')->where('id', $gatewayBinding->id)->update([
            'gateway_slug' => 'gateway-paypal',
        ]);

        try {
            $service->approveAndSubmit($gatewayBinding, (int) $approver->id);
            $this->fail('A refund with a changed gateway binding must not be submitted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('payment_refunds', [
            'id' => $gatewayBinding->id,
            'status' => 'requested',
            'gateway_slug' => 'gateway-paypal',
        ]);
        Http::assertNothingSent();
    }

}