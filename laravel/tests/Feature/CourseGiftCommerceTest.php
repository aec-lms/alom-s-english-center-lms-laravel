<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseManagement\Models\Course;
use Tests\TestCase;

class CourseGiftCommerceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'fees-management', 'marketing-management', 'universal-payment']);
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
}
