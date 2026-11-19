<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\CommerceCart\Services\CommerceCatalog;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Plugins\Modules\UniversalPayment\Services\StorefrontPaymentGatewayService;
use Plugins\Modules\ReferralIncome\Services\ReferralIncomeService;
use Tests\TestCase;

class CourseGiftCommerceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'fees-management', 'marketing-management', 'universal-payment']);
    }

    public function test_known_recipient_from_another_branch_cannot_add_course_gift_to_unified_cart(): void
    {
        $sender = $this->createUserWithRole('student', 11);
        $recipient = $this->createUserWithRole('student', 22, 'other-branch-recipient@example.test');
        $course = Course::query()->create([
            'code' => 'CROSS-BRANCH-GIFT-001',
            'title' => 'Branch Restricted Gift Course',
            'slug' => 'branch-restricted-gift-course',
            'price' => 1500,
            'status' => 'published',
            'branch_id' => 11,
        ]);
        $token = str_repeat('B', 64);
        $giftId = DB::table('course_gifts')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => $recipient->id,
            'recipient_email' => $recipient->email,
            'course_id' => $course->id,
            'invoice_id' => null,
            'redeem_token_hash' => hash('sha256', $token),
            'redeem_token_encrypted' => encrypt($token),
            'status' => 'awaiting_payment',
            'currency' => 'BDT',
            'amount' => 1500,
            'subtotal_amount' => 1500,
            'discount_amount' => 0,
            'coupon_reservation_key' => null,
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(CommerceCatalog::class)->resolve('gift_course', $giftId, $sender);
            $this->fail('A branch-restricted gift must not be checked out for a recipient assigned to another branch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('item', $exception->errors());
        }
    }

    public function test_gift_and_voucher_with_unverified_linked_payment_cannot_be_redeemed(): void
    {
        $sender = $this->createUserWithRole('student');
        $recipient = $this->createUserWithRole('student');
        $recipient->update(['email' => 'unverified-gift-recipient@example.test']);

        $giftPayment = UniversalPaymentTransaction::query()->create([
            'reference' => 'UNVERIFIED-GIFT-' . $sender->id,
            'payer_name' => $sender->name,
            'payer_contact' => '01700000000',
            'item_title' => 'Gift Course',
            'gateway' => 'sslcommerz',
            'currency' => 'BDT',
            'amount' => 1500,
            'user_id' => $sender->id,
            'status' => 'pending',
            'notes' => json_encode(['purpose' => 'course_gift'], JSON_THROW_ON_ERROR),
        ]);
        $voucherPayment = UniversalPaymentTransaction::query()->create([
            'reference' => 'UNVERIFIED-VOUCHER-' . $sender->id,
            'payer_name' => $sender->name,
            'payer_contact' => '01700000000',
            'item_title' => 'Gift Voucher',
            'gateway' => 'sslcommerz',
            'currency' => 'BDT',
            'amount' => 1200,
            'user_id' => $sender->id,
            'status' => 'pending',
            'notes' => json_encode(['purpose' => 'course_gift_voucher'], JSON_THROW_ON_ERROR),
        ]);

        $course = Course::query()->create([
            'code' => 'UNVERIFIED-GIFT-001',
            'title' => 'Unverified Gift Course',
            'slug' => 'unverified-gift-course',
            'price' => 1500,
            'status' => 'published',
        ]);
        $giftToken = str_repeat('U', 64);
        $giftId = DB::table('course_gifts')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => null,
            'recipient_email' => $recipient->email,
            'course_id' => $course->id,
            'invoice_id' => null,
            'payment_transaction_id' => $giftPayment->id,
            'redeem_token_hash' => hash('sha256', $giftToken),
            'redeem_token_encrypted' => encrypt($giftToken),
            'status' => 'paid',
            'currency' => 'BDT',
            'amount' => 1500,
            'paid_at' => now(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $voucherToken = str_repeat('W', 64);
        $voucherId = DB::table('course_gift_vouchers')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_email' => $recipient->email,
            'recipient_name' => 'Recipient',
            'redeem_token_hash' => hash('sha256', $voucherToken),
            'redeem_token_encrypted' => encrypt($voucherToken),
            'payment_transaction_id' => $voucherPayment->id,
            'currency' => 'BDT',
            'amount' => 1200,
            'remaining_amount' => 1200,
            'status' => 'paid',
            'paid_at' => now(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($recipient)->post('/student/course-commerce/gifts/redeem/' . $giftToken)->assertStatus(409);
        $this->actingAs($recipient)->post('/student/course-commerce/vouchers/redeem/' . $voucherToken)->assertStatus(409);

        $this->assertDatabaseHas('course_gifts', ['id' => $giftId, 'status' => 'paid', 'recipient_user_id' => null]);
        $this->assertDatabaseHas('course_gift_vouchers', ['id' => $voucherId, 'status' => 'paid', 'remaining_amount' => 1200]);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('course_gift_wallet_transactions', 0);
    }

    public function test_expired_paid_course_gift_cannot_be_redeemed(): void
    {
        $sender = $this->createUserWithRole('student');
        $recipient = $this->createUserWithRole('student');
        $recipient->update(['email' => 'expired-gift-recipient@example.test']);
        $course = Course::query()->create([
            'code' => 'EXPIRED-GIFT-001',
            'title' => 'Expired Gift Course',
            'slug' => 'expired-gift-course',
            'price' => 900,
            'status' => 'published',
        ]);
        $token = str_repeat('E', 64);
        $giftId = DB::table('course_gifts')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => null,
            'recipient_email' => $recipient->email,
            'course_id' => $course->id,
            'invoice_id' => null,
            'redeem_token_hash' => hash('sha256', $token),
            'redeem_token_encrypted' => encrypt($token),
            'status' => 'paid',
            'currency' => 'BDT',
            'amount' => 900,
            'paid_at' => now()->subDays(91),
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subDays(91),
            'updated_at' => now(),
        ]);

        $this->actingAs($recipient)->post('/student/course-commerce/gifts/redeem/' . $token)
            ->assertStatus(410);

        $this->assertDatabaseHas('course_gifts', ['id' => $giftId, 'status' => 'paid', 'recipient_user_id' => null]);
        $this->assertDatabaseMissing('enrollments', ['user_id' => $recipient->id, 'course_id' => $course->id]);
    }

    public function test_paid_course_gift_cannot_be_redeemed_outside_the_recipient_current_branch(): void
    {
        $sender = $this->createUserWithRole('student', 11);
        $recipient = $this->createUserWithRole('student', 22, 'branch-gift-recipient@example.test');
        $course = Course::query()->create([
            'code' => 'GIFT-BRANCH-001',
            'title' => 'Branch Restricted Gift',
            'slug' => 'branch-restricted-gift',
            'price' => 1500,
            'status' => 'published',
            'branch_id' => 11,
        ]);
        $token = str_repeat('R', 64);
        $giftId = DB::table('course_gifts')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => null,
            'recipient_email' => $recipient->email,
            'course_id' => $course->id,
            'invoice_id' => null,
            'redeem_token_hash' => hash('sha256', $token),
            'redeem_token_encrypted' => encrypt($token),
            'status' => 'paid',
            'currency' => 'BDT',
            'amount' => 1500,
            'paid_at' => now(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($recipient)->post('/student/course-commerce/gifts/redeem/' . $token)->assertForbidden();

        $this->assertDatabaseHas('course_gifts', ['id' => $giftId, 'status' => 'paid', 'recipient_user_id' => null]);
        $this->assertDatabaseMissing('enrollments', ['user_id' => $recipient->id, 'course_id' => $course->id]);
    }

    public function test_paid_course_gift_can_only_be_redeemed_by_recipient_email_and_only_once(): void
    {
        $sender = $this->createUserWithRole('student');
        $recipient = $this->createUserWithRole('student');
        $recipient->update(['email' => 'gift-recipient@example.test']);
        $course = Course::query()->create([
            'code' => 'GIFT-IELTS-001',
            'title' => 'Gifted IELTS Course',
            'slug' => 'gifted-ielts-course',
            'price' => 1500,
            'status' => 'published',
        ]);
        $token = str_repeat('G', 64);
        $giftId = DB::table('course_gifts')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => null,
            'recipient_email' => $recipient->email,
            'course_id' => $course->id,
            'invoice_id' => null,
            'redeem_token_hash' => hash('sha256', $token),
            'redeem_token_encrypted' => encrypt($token),
            'status' => 'paid',
            'currency' => 'BDT',
            'amount' => 1500,
            'paid_at' => now(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wrongRecipient = $this->createUserWithRole('student');
        $this->actingAs($wrongRecipient)->post('/student/course-commerce/gifts/redeem/' . $token)->assertForbidden();

        $this->actingAs($recipient)->post('/student/course-commerce/gifts/redeem/' . $token)
            ->assertRedirect(route('plugins.enrollments.my'));
        $this->assertDatabaseHas('course_gifts', ['id' => $giftId, 'status' => 'redeemed', 'recipient_user_id' => $recipient->id]);
        $this->assertDatabaseHas('enrollments', ['user_id' => $recipient->id, 'course_id' => $course->id, 'status' => 'active']);

        $this->actingAs($recipient)->post('/student/course-commerce/gifts/redeem/' . $token)->assertStatus(409);
        $this->assertSame(1, DB::table('enrollments')->where('user_id', $recipient->id)->where('course_id', $course->id)->count());
    }

    public function test_paid_gift_voucher_is_credited_to_recipient_wallet_exactly_once(): void
    {
        $sender = $this->createUserWithRole('student');
        $recipient = $this->createUserWithRole('student');
        $recipient->update(['email' => 'voucher-recipient@example.test']);
        $token = str_repeat('V', 64);
        $voucherId = DB::table('course_gift_vouchers')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_email' => $recipient->email,
            'recipient_name' => 'Voucher Recipient',
            'message' => 'Enjoy your learning!',
            'redeem_token_hash' => hash('sha256', $token),
            'redeem_token_encrypted' => encrypt($token),
            'currency' => 'BDT',
            'amount' => 1200,
            'remaining_amount' => 1200,
            'status' => 'paid',
            'paid_at' => now(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wrongRecipient = $this->createUserWithRole('student');
        $this->actingAs($wrongRecipient)->post('/student/course-commerce/vouchers/redeem/' . $token)->assertForbidden();

        $this->actingAs($recipient)->post('/student/course-commerce/vouchers/redeem/' . $token)
            ->assertRedirect(route('course-commerce.cart'));
        $this->assertDatabaseHas('course_gift_vouchers', [
            'id' => $voucherId,
            'status' => 'redeemed',
            'recipient_user_id' => $recipient->id,
            'remaining_amount' => 0,
        ]);
        $this->assertDatabaseHas('course_gift_wallets', [
            'user_id' => $recipient->id,
            'currency' => 'BDT',
            'balance' => 1200,
        ]);
        $this->assertDatabaseHas('course_gift_wallet_transactions', [
            'voucher_id' => $voucherId,
            'user_id' => $recipient->id,
            'type' => 'credit',
            'status' => 'completed',
            'reference' => 'gift-voucher-credit:' . $voucherId,
        ]);
        $this->assertDatabaseHas('course_gift_voucher_redemptions', [
            'voucher_id' => $voucherId,
            'user_id' => $recipient->id,
            'course_id' => null,
            'amount' => 1200,
        ]);

        $this->actingAs($recipient)->post('/student/course-commerce/vouchers/redeem/' . $token)->assertStatus(409);
        $this->assertSame(1, DB::table('course_gift_wallet_transactions')->where('reference', 'gift-voucher-credit:' . $voucherId)->count());
    }
    public function test_gift_and_voucher_redemption_recheck_the_recipient_email_under_lock(): void
    {
        $sender = $this->createUserWithRole('student');
        $recipient = $this->createUserWithRole('student');
        $originalEmail = 'locked-recipient@example.test';
        $recipient->update(['email' => $originalEmail]);
        $course = Course::query()->create([
            'code' => 'LOCKED-GIFT-001',
            'title' => 'Locked Gift Course',
            'slug' => 'locked-gift-course',
            'price' => 1000,
            'status' => 'published',
        ]);
        $giftToken = str_repeat('L', 64);
        $giftId = DB::table('course_gifts')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => null,
            'recipient_email' => $originalEmail,
            'course_id' => $course->id,
            'invoice_id' => null,
            'redeem_token_hash' => hash('sha256', $giftToken),
            'redeem_token_encrypted' => encrypt($giftToken),
            'status' => 'paid',
            'currency' => 'BDT',
            'amount' => 1000,
            'paid_at' => now(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $voucherToken = str_repeat('M', 64);
        $voucherId = DB::table('course_gift_vouchers')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => null,
            'recipient_email' => $originalEmail,
            'recipient_name' => 'Locked Recipient',
            'redeem_token_hash' => hash('sha256', $voucherToken),
            'redeem_token_encrypted' => encrypt($voucherToken),
            'currency' => 'BDT',
            'amount' => 1000,
            'remaining_amount' => 1000,
            'status' => 'paid',
            'paid_at' => now(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($recipient);
        DB::table('users')->where('id', $recipient->id)->update(['email' => 'changed-recipient@example.test']);

        $this->post('/student/course-commerce/gifts/redeem/' . $giftToken)->assertForbidden();
        $this->post('/student/course-commerce/vouchers/redeem/' . $voucherToken)->assertForbidden();

        $this->assertDatabaseHas('course_gifts', ['id' => $giftId, 'status' => 'paid', 'recipient_user_id' => null]);
        $this->assertDatabaseHas('course_gift_vouchers', ['id' => $voucherId, 'status' => 'paid', 'recipient_user_id' => null]);
        $this->assertDatabaseMissing('enrollments', ['user_id' => $recipient->id, 'course_id' => $course->id]);
        $this->assertDatabaseMissing('course_gift_wallets', ['user_id' => $recipient->id]);
    }

    public function test_redemption_rejects_source_invoice_amount_or_currency_mismatch(): void
    {
        $sender = $this->createUserWithRole('student');
        $recipient = $this->createUserWithRole('student');
        $recipient->update(['email' => 'invoice-mismatch-recipient@example.test']);
        $course = Course::query()->create([
            'code' => 'INVOICE-MISMATCH-001',
            'title' => 'Invoice Mismatch Course',
            'slug' => 'invoice-mismatch-course',
            'price' => 1200,
            'status' => 'published',
        ]);

        $amountInvoice = FeeInvoice::query()->create([
            'invoice_number' => 'GIFT-AMOUNT-MISMATCH-' . $recipient->id,
            'payer_name' => $sender->name,
            'payer_email' => $sender->email,
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'net_amount' => 1000,
            'paid_amount' => 1000,
            'currency' => 'BDT',
            'status' => 'paid',
        ]);
        $giftToken = str_repeat('A', 64);
        $giftId = DB::table('course_gifts')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => null,
            'recipient_email' => $recipient->email,
            'course_id' => $course->id,
            'invoice_id' => $amountInvoice->id,
            'redeem_token_hash' => hash('sha256', $giftToken),
            'redeem_token_encrypted' => encrypt($giftToken),
            'status' => 'paid',
            'currency' => 'BDT',
            'amount' => 1200,
            'paid_at' => now(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $currencyInvoice = FeeInvoice::query()->create([
            'invoice_number' => 'VOUCHER-CURRENCY-MISMATCH-' . $recipient->id,
            'payer_name' => $sender->name,
            'payer_email' => $sender->email,
            'subtotal_amount' => 1500,
            'discount_amount' => 0,
            'net_amount' => 1500,
            'paid_amount' => 1500,
            'currency' => 'USD',
            'status' => 'paid',
        ]);
        $voucherToken = str_repeat('C', 64);
        $voucherId = DB::table('course_gift_vouchers')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => null,
            'recipient_email' => $recipient->email,
            'recipient_name' => 'Invoice Mismatch Recipient',
            'invoice_id' => $currencyInvoice->id,
            'redeem_token_hash' => hash('sha256', $voucherToken),
            'redeem_token_encrypted' => encrypt($voucherToken),
            'currency' => 'BDT',
            'amount' => 1500,
            'remaining_amount' => 1500,
            'status' => 'paid',
            'paid_at' => now(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($recipient)->post('/student/course-commerce/gifts/redeem/' . $giftToken)->assertStatus(409);
        $this->post('/student/course-commerce/vouchers/redeem/' . $voucherToken)->assertStatus(409);

        $this->assertDatabaseHas('course_gifts', ['id' => $giftId, 'status' => 'paid', 'recipient_user_id' => null]);
        $this->assertDatabaseHas('course_gift_vouchers', ['id' => $voucherId, 'status' => 'paid', 'recipient_user_id' => null]);
        $this->assertDatabaseMissing('enrollments', ['user_id' => $recipient->id, 'course_id' => $course->id]);
        $this->assertDatabaseMissing('course_gift_wallets', ['user_id' => $recipient->id]);
    }

    public function test_voucher_sender_cannot_claim_their_own_voucher(): void
    {
        $sender = $this->createUserWithRole('student');
        $token = str_repeat('S', 64);
        $voucherId = DB::table('course_gift_vouchers')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_email' => $sender->email,
            'recipient_name' => $sender->name,
            'redeem_token_hash' => hash('sha256', $token),
            'redeem_token_encrypted' => encrypt($token),
            'currency' => 'BDT',
            'amount' => 1000,
            'remaining_amount' => 1000,
            'status' => 'paid',
            'paid_at' => now(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($sender)->get('/student/course-commerce/vouchers/redeem/' . $token)->assertForbidden();
        $this->actingAs($sender)->post('/student/course-commerce/vouchers/redeem/' . $token)->assertForbidden();

        $this->assertDatabaseHas('course_gift_vouchers', ['id' => $voucherId, 'status' => 'paid', 'recipient_user_id' => null]);
        $this->assertDatabaseMissing('course_gift_wallets', ['user_id' => $sender->id]);
    }

    public function test_voucher_redemption_rejects_remaining_balance_above_original_amount(): void
    {
        $sender = $this->createUserWithRole('student');
        $recipient = $this->createUserWithRole('student');
        $recipient->update(['email' => 'invalid-balance-recipient@example.test']);
        $token = str_repeat('I', 64);
        $voucherId = DB::table('course_gift_vouchers')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_email' => $recipient->email,
            'recipient_name' => 'Invalid Balance Recipient',
            'redeem_token_hash' => hash('sha256', $token),
            'redeem_token_encrypted' => encrypt($token),
            'currency' => 'BDT',
            'amount' => 1000,
            'remaining_amount' => 1500,
            'status' => 'paid',
            'paid_at' => now(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($recipient)->post('/student/course-commerce/vouchers/redeem/' . $token)->assertStatus(409);

        $this->assertDatabaseHas('course_gift_vouchers', ['id' => $voucherId, 'status' => 'paid', 'remaining_amount' => 1500]);
        $this->assertDatabaseMissing('course_gift_wallets', ['user_id' => $recipient->id]);
    }

    public function test_sslcommerz_course_cart_callback_preserves_order_metadata_and_activates_course_once(): void
    {
        $this->activatePlugins(['course-management', 'fees-management', 'marketing-management', 'universal-payment', 'gateway-sslcommerz', 'referral-income']);
        $buyer = $this->createUserWithRole('student');
        $referrer = $this->createUserWithRole('teacher');
        $referrals = app(ReferralIncomeService::class);
        $referrals->applyCode($buyer, $referrals->createOrGetCode($referrer));
        $course = Course::query()->create([
            'code' => 'CALLBACK-COURSE-001',
            'title' => 'Callback Verified Course',
            'slug' => 'callback-verified-course',
            'price' => 1000,
            'status' => 'published',
        ]);
        app(PluginManager::class)->saveSettings('gateway-sslcommerz', [
            'store_id' => 'test-store-id',
            'store_password' => 'test-store-password',
            'sandbox_mode' => '1',
        ]);

        $cartId = DB::table('course_carts')->insertGetId([
            'user_id' => $buyer->id,
            'status' => 'checkout_pending',
            'currency' => 'BDT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('course_cart_orders')->insertGetId([
            'cart_id' => $cartId,
            'user_id' => $buyer->id,
            'branch_id' => null,
            'invoice_id' => null,
            'payment_transaction_id' => null,
            'coupon_id' => null,
            'coupon_code' => null,
            'coupon_reservation_key' => null,
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'net_amount' => 1000,
            'wallet_credit_amount' => 0,
            'wallet_reservation_key' => null,
            'currency' => 'BDT',
            'status' => 'awaiting_payment',
            'paid_at' => null,
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_cart_order_items')->insert([
            'order_id' => $orderId,
            'course_id' => $course->id,
            'course_title' => $course->title,
            'unit_price' => 1000,
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transaction = UniversalPaymentTransaction::query()->create([
            'reference' => 'COURSE-CALLBACK-' . $buyer->id,
            'payer_name' => $buyer->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Course Cart Order #' . $orderId,
            'gateway' => 'sslcommerz',
            'currency' => 'BDT',
            'amount' => 1000,
            'external_trx_id' => null,
            'user_id' => $buyer->id,
            'branch_id' => null,
            'status' => 'pending',
            'notes' => json_encode(['purpose' => 'course_cart', 'course_cart_order_id' => $orderId], JSON_THROW_ON_ERROR),
        ]);
        DB::table('course_cart_orders')->where('id', $orderId)->update([
            'payment_transaction_id' => $transaction->id,
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://sandbox.sslcommerz.com/gwprocess/v4/api.php' => Http::response([
                'status' => 'SUCCESS',
                'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gateway/checkout',
                'sessionkey' => 'course-session-key',
            ], 200),
            'https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php*' => Http::response([
                'status' => 'VALID',
                'tran_id' => $transaction->reference,
                'val_id' => 'COURSE-VALIDATION-1',
                'currency' => 'BDT',
                'amount' => '1000.00',
                'risk_level' => '0',
                'bank_tran_id' => 'BANK-COURSE-1',
            ], 200),
        ]);

        $gateway = app(StorefrontPaymentGatewayService::class);
        $checkoutUrl = $gateway->initiateSslcommerz(
            $transaction,
            (object) ['title' => 'Callback Verified Course'],
            $buyer,
            '01712345678',
            ['purpose' => 'course_cart', 'course_cart_order_id' => $orderId]
        );
        $this->assertSame('https://sandbox.sslcommerz.com/gateway/checkout', $checkoutUrl);
        $notes = json_decode((string) $transaction->fresh()->notes, true);
        $this->assertSame('course_cart', $notes['purpose'] ?? null);
        $this->assertSame($orderId, (int) ($notes['course_cart_order_id'] ?? 0));

        $gateway->verifySslcommerz([
            'tran_id' => $transaction->reference,
            'val_id' => 'COURSE-VALIDATION-1',
        ]);
        $gateway->verifySslcommerz([
            'tran_id' => $transaction->reference,
            'val_id' => 'COURSE-VALIDATION-1',
        ]);

        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $transaction->id,
            'status' => 'verified',
            'external_trx_id' => 'COURSE-VALIDATION-1',
        ]);
        $this->assertDatabaseHas('course_cart_orders', [
            'id' => $orderId,
            'status' => 'paid',
        ]);
        $verifiedNotes = json_decode((string) UniversalPaymentTransaction::query()->findOrFail($transaction->id)->notes, true);
        $this->assertDatabaseHas('course_carts', ['id' => $cartId, 'status' => 'completed']);
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $buyer->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
        $this->assertSame(1, DB::table('enrollments')->where('user_id', $buyer->id)->where('course_id', $course->id)->count());
        $this->assertSame(1, DB::table('fee_invoices')->where('invoice_number', 'like', 'CART-%')->count());
        $this->assertDatabaseHas('referral_commissions', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'source_type' => 'course_cart_order',
            'source_id' => (string) $orderId,
            'commission_amount' => 100,
            'commission_percent' => 10,
            'currency' => 'BDT',
            'status' => 'pending',
        ]);
    }

    public function test_definitive_gateway_rejections_mark_course_gifts_and_vouchers_as_payment_failed(): void
    {
        $this->activatePlugins(['course-management', 'fees-management', 'marketing-management', 'universal-payment', 'gateway-sslcommerz']);
        $sender = $this->createUserWithRole('student');
        $course = Course::query()->create([
            'code' => 'FAILED-GIFT-001',
            'title' => 'Failed Gift Checkout Course',
            'slug' => 'failed-gift-checkout-course',
            'price' => 1500,
            'status' => 'published',
        ]);
        app(PluginManager::class)->saveSettings('gateway-sslcommerz', [
            'store_id' => 'test-store-id',
            'store_password' => 'test-store-password',
            'sandbox_mode' => '1',
        ]);
        Http::fake([
            'https://sandbox.sslcommerz.com/gwprocess/v4/api.php' => Http::response([
                'status' => 'FAILED',
                'failedreason' => 'Test provider rejection',
            ], 200),
        ]);

        $this->actingAs($sender)->from('/courses/' . $course->slug)
            ->post('/student/course-commerce/gifts/' . $course->id, [
                'recipient_email' => 'gift-failure-recipient@example.test',
                'recipient_name' => 'Gift Recipient',
                'payer_contact' => '01712345678',
            ])->assertRedirect('/courses/' . $course->slug)->assertSessionHasErrors('gift');

        $this->assertDatabaseHas('course_gifts', [
            'sender_user_id' => $sender->id,
            'recipient_email' => 'gift-failure-recipient@example.test',
            'status' => 'payment_failed',
        ]);
        $this->assertDatabaseHas('universal_payment_transactions', [
            'user_id' => $sender->id,
            'status' => 'failed',
            'gateway' => 'sslcommerz',
        ]);

        $this->actingAs($sender)->from('/student/course-commerce/vouchers/buy')
            ->post('/student/course-commerce/vouchers/buy', [
                'recipient_email' => 'voucher-failure-recipient@example.test',
                'recipient_name' => 'Voucher Recipient',
                'amount' => 1200,
                'payer_contact' => '01712345678',
            ])->assertRedirect('/student/course-commerce/vouchers/buy')->assertSessionHasErrors('voucher');

        $this->assertDatabaseHas('course_gift_vouchers', [
            'sender_user_id' => $sender->id,
            'recipient_email' => 'voucher-failure-recipient@example.test',
            'status' => 'payment_failed',
        ]);
        $this->assertSame(2, DB::table('universal_payment_transactions')
            ->where('user_id', $sender->id)->where('gateway', 'sslcommerz')->where('status', 'failed')->count());
        Http::assertSentCount(2);
    }

    public function test_student_cannot_start_a_second_course_checkout_while_an_order_is_unresolved(): void
    {
        $this->activatePlugins(['course-management', 'fees-management', 'marketing-management', 'universal-payment', 'gateway-sslcommerz']);
        $buyer = $this->createUserWithRole('student');
        $course = Course::query()->create([
            'code' => 'DUPLICATE-CHECKOUT-001',
            'title' => 'Duplicate Checkout Guard Course',
            'slug' => 'duplicate-checkout-guard-course',
            'price' => 1000,
            'status' => 'published',
        ]);
        $pendingCartId = DB::table('course_carts')->insertGetId([
            'user_id' => $buyer->id, 'status' => 'checkout_pending', 'currency' => 'BDT',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $activeCartId = DB::table('course_carts')->insertGetId([
            'user_id' => $buyer->id, 'status' => 'active', 'currency' => 'BDT',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('course_cart_items')->insert([
            'cart_id' => $activeCartId, 'course_id' => $course->id, 'unit_price' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('course_cart_orders')->insert([
            'cart_id' => $pendingCartId,
            'user_id' => $buyer->id,
            'branch_id' => null,
            'invoice_id' => null,
            'payment_transaction_id' => null,
            'coupon_id' => null,
            'coupon_code' => null,
            'coupon_reservation_key' => null,
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'net_amount' => 1000,
            'wallet_credit_amount' => 0,
            'wallet_reservation_key' => null,
            'currency' => 'BDT',
            'status' => 'awaiting_payment',
            'paid_at' => null,
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($buyer)->post('/student/course-commerce/cart/items/' . $course->id)
            ->assertStatus(409);
        $this->actingAs($buyer)->post('/student/course-commerce/checkout', [
            'payer_contact' => '01712345678',
        ])->assertStatus(409);

        $this->assertDatabaseCount('course_cart_orders', 1);
        $this->assertDatabaseHas('course_carts', ['id' => $activeCartId, 'status' => 'active']);
        $this->assertDatabaseCount('universal_payment_transactions', 0);
    }

    public function test_wallet_fully_covers_course_cart_without_enabling_an_online_gateway(): void
    {
        $buyer = $this->createUserWithRole('student');
        $course = Course::query()->create([
            'code' => 'WALLET-ONLY-001',
            'title' => 'Wallet Only Course',
            'slug' => 'wallet-only-course',
            'price' => 1000,
            'status' => 'published',
        ]);
        $cartId = DB::table('course_carts')->insertGetId([
            'user_id' => $buyer->id,
            'status' => 'active',
            'currency' => 'BDT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_cart_items')->insert([
            'cart_id' => $cartId,
            'course_id' => $course->id,
            'unit_price' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $walletId = DB::table('course_gift_wallets')->insertGetId([
            'user_id' => $buyer->id,
            'currency' => 'BDT',
            'balance' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // This test deliberately does not enable the SSLCOMMERZ gateway.
        $this->actingAs($buyer)->post('/student/course-commerce/checkout', [
            'payer_contact' => '01712345678',
        ])->assertRedirect(route('course-commerce.cart'))
            ->assertSessionHas('status', 'Your course wallet or coupon covered the full cart. All selected courses are now active.');

        $this->assertDatabaseHas('course_cart_orders', [
            'user_id' => $buyer->id,
            'status' => 'paid',
            'wallet_credit_amount' => 1000,
            'currency' => 'BDT',
        ]);
        $this->assertDatabaseHas('course_gift_wallets', ['id' => $walletId, 'balance' => 0]);
        $this->assertDatabaseHas('course_gift_wallet_transactions', [
            'wallet_id' => $walletId,
            'user_id' => $buyer->id,
            'type' => 'debit',
            'status' => 'completed',
            'amount' => 1000,
        ]);
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $buyer->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('universal_payment_transactions', 0);
    }


    public function test_refunded_or_partially_paid_source_invoice_blocks_gift_and_voucher_redemption(): void
    {
        $sender = $this->createUserWithRole('student');
        $recipient = $this->createUserWithRole('student');
        $recipient->update(['email' => 'refund-recipient@example.test']);
        $course = Course::query()->create([
            'code' => 'REFUNDED-GIFT-001',
            'title' => 'Refunded Gift Course',
            'slug' => 'refunded-gift-course',
            'price' => 1500,
            'status' => 'published',
        ]);
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'REFUNDED-GIFT-INV-' . $sender->id,
            'payer_name' => $sender->name,
            'payer_email' => $sender->email,
            'subtotal_amount' => 1500,
            'discount_amount' => 0,
            'net_amount' => 1500,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'refunded',
        ]);

        $partialInvoice = FeeInvoice::query()->create([
            'invoice_number' => 'PARTIAL-GIFT-INV-' . $sender->id,
            'payer_name' => $sender->name,
            'payer_email' => $sender->email,
            'subtotal_amount' => 1200,
            'discount_amount' => 0,
            'net_amount' => 1200,
            'paid_amount' => 600,
            'currency' => 'BDT',
            'status' => 'partial',
        ]);

        $giftToken = str_repeat('R', 64);
        $giftId = DB::table('course_gifts')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => null,
            'recipient_email' => $recipient->email,
            'course_id' => $course->id,
            'invoice_id' => $invoice->id,
            'redeem_token_hash' => hash('sha256', $giftToken),
            'redeem_token_encrypted' => encrypt($giftToken),
            'status' => 'paid',
            'currency' => 'BDT',
            'amount' => 1500,
            'paid_at' => now(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $voucherToken = str_repeat('S', 64);
        $voucherId = DB::table('course_gift_vouchers')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => null,
            'recipient_email' => $recipient->email,
            'recipient_name' => 'Refund Recipient',
            'message' => null,
            'redeem_token_hash' => hash('sha256', $voucherToken),
            'redeem_token_encrypted' => encrypt($voucherToken),
            'invoice_id' => $partialInvoice->id,
            'currency' => 'BDT',
            'amount' => 1200,
            'remaining_amount' => 1200,
            'status' => 'paid',
            'paid_at' => now(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($recipient)
            ->post('/student/course-commerce/gifts/redeem/' . $giftToken)
            ->assertStatus(409);
        $this->actingAs($recipient)
            ->post('/student/course-commerce/vouchers/redeem/' . $voucherToken)
            ->assertStatus(409);

        $this->assertDatabaseHas('course_gifts', ['id' => $giftId, 'status' => 'paid']);
        $this->assertDatabaseHas('course_gift_vouchers', ['id' => $voucherId, 'status' => 'paid']);
        $this->assertDatabaseMissing('enrollments', ['user_id' => $recipient->id, 'course_id' => $course->id]);
        $this->assertDatabaseMissing('course_gift_wallet_transactions', ['voucher_id' => $voucherId]);
        $this->assertDatabaseMissing('course_gift_wallets', ['user_id' => $recipient->id, 'currency' => 'BDT']);
    }

}
