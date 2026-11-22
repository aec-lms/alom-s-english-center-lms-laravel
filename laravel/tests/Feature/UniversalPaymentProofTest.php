<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UniversalPaymentProofTest extends TestCase
{
    public function test_offline_receipt_proof_is_stored_privately_and_downloaded_through_authorized_route(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['universal-payment']);
        Storage::fake('local');
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/plugins/universal-payment/transactions', [
            'payer_name' => 'Proof Test Payer',
            'payer_contact' => '01712345678',
            'item_title' => 'IELTS course deposit',
            'gateway' => 'bank_cash',
            'amount' => 1500,
            'external_trx_id' => 'BANK-PROOF-001',
            'proof_file' => UploadedFile::fake()->create('bank-receipt.pdf', 120, 'application/pdf'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $transaction = DB::table('universal_payment_transactions')->where('external_trx_id', 'BANK-PROOF-001')->first();
        $this->assertNotNull($transaction);
        $this->assertSame('application/pdf', $transaction->proof_mime);
        $this->assertGreaterThan(0, (int) $transaction->proof_size);
        $this->assertStringStartsWith('universal-payment-proofs/', $transaction->proof_path);
        Storage::disk('local')->assertExists($transaction->proof_path);

        $this->actingAs($admin)->get('/plugins/universal-payment/transactions/' . $transaction->id . '/proof')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
    public function test_unassigned_branch_manager_cannot_access_universal_payments_or_change_global_gateways(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'universal-payment']);

        $manager = $this->createUserWithRole('branch_manager');
        $permissionIds = DB::table('permissions')
            ->where('slug', 'payments.universal.manage')
            ->pluck('id');
        $this->assertCount(1, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $this->actingAs($manager)
            ->get('/plugins/universal-payment')
            ->assertForbidden();

        $this->actingAs($manager)
            ->post('/plugins/universal-payment/gateways', [
                'default_currency' => 'BDT',
                'gateways' => ['sslcommerz'],
            ])
            ->assertForbidden();

        $this->actingAs($manager)
            ->post('/plugins/universal-payment/transactions', [
                'payer_name' => 'Unassigned Manager',
                'payer_contact' => '01700000000',
                'item_title' => 'Test course',
                'gateway' => 'bank_cash',
                'amount' => 1000,
                'external_trx_id' => 'UNASSIGNED-001',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('universal_payment_transactions', [
            'external_trx_id' => 'UNASSIGNED-001',
        ]);
    }

    public function test_payment_review_reloads_branch_assignment_after_authenticated_session_was_created(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'universal-payment']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'UPAY-REVIEW-A',
            'name' => 'Payment Review Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'UPAY-REVIEW-B',
            'name' => 'Payment Review Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = $this->createUserWithRole('branch_manager', (int) $branchA);
        $permissionId = DB::table('permissions')->where('slug', 'payments.universal.manage')->value('id');
        $this->assertNotNull($permissionId);
        $manager->roles()->first()->permissions()->syncWithoutDetaching([(int) $permissionId]);

        $transactionId = DB::table('universal_payment_transactions')->insertGetId([
            'reference' => 'UPAY-STALE-BRANCH-' . $manager->id,
            'payer_name' => 'Branch Review Payer',
            'payer_contact' => '01700000000',
            'item_title' => 'Branch scoped payment',
            'gateway' => 'bank_cash',
            'currency' => 'BDT',
            'amount' => 500,
            'external_trx_id' => 'UPAY-STALE-BRANCH-EXT-' . $manager->id,
            'branch_id' => $branchA,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Keep the authenticated model at branch A while the persisted account
        // is reassigned to branch B, simulating a request with stale session state.
        $this->actingAs($manager);
        DB::table('users')->where('id', $manager->id)->update(['branch_id' => $branchB]);

        $this->post('/plugins/universal-payment/transactions/' . $transactionId . '/status', [
            'status' => 'verified',
            'payment_reference' => 'UPAY-STALE-BRANCH-VERIFY-' . $manager->id,
        ])->assertNotFound();

        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $transactionId,
            'status' => 'pending',
            'verified_by' => null,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'universal_payment.status_updated',
            'entity_id' => (string) $transactionId,
        ]);
    }

}
