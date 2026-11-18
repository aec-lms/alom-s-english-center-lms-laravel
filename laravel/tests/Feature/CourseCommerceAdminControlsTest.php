<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SystemSetting;
use Plugins\Modules\MarketingManagement\Models\PromoCoupon;
use Tests\TestCase;

class CourseCommerceAdminControlsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markSystemInstalled();
        $this->activatePlugins(['marketing-management', 'course-management']);
    }

    public function test_admin_can_change_gift_voucher_policy_and_purchase_settings_are_persisted(): void
    {
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->put(route('plugins.marketing.gift-voucher-settings.update'), [
            'enabled' => '1',
            'minimum_amount' => 250,
            'maximum_amount' => 25000,
            'validity_days' => 90,
            'allow_personal_message' => '1',
        ])->assertRedirect();

        $stored = json_decode((string) SystemSetting::query()->where('key', 'course_commerce.gift_vouchers')->value('value'), true);
        $this->assertSame(true, $stored['enabled']);
        $this->assertEquals(250.0, (float) $stored['minimum_amount']);
        $this->assertEquals(25000.0, (float) $stored['maximum_amount']);
        $this->assertSame(90, $stored['validity_days']);
        $this->assertSame(true, $stored['allow_personal_message']);
    }

    public function test_admin_can_suspend_and_reactivate_only_unclaimed_paid_vouchers(): void
    {
        $admin = $this->createUserWithRole('admin');
        $sender = $this->createUserWithRole('student');
        $voucherId = DB::table('course_gift_vouchers')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_email' => 'voucher-owner@example.test',
            'redeem_token_hash' => hash('sha256', str_repeat('X', 64)),
            'redeem_token_encrypted' => encrypt(str_repeat('X', 64)),
            'currency' => 'BDT',
            'amount' => 1000,
            'remaining_amount' => 1000,
            'status' => 'paid',
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->patch(route('plugins.marketing.gift-vouchers.status', $voucherId), [
            'status' => 'suspended',
        ])->assertRedirect();
        $this->assertDatabaseHas('course_gift_vouchers', ['id' => $voucherId, 'status' => 'suspended']);

        $this->actingAs($admin)->patch(route('plugins.marketing.gift-vouchers.status', $voucherId), [
            'status' => 'paid',
        ])->assertRedirect();
        $this->assertDatabaseHas('course_gift_vouchers', ['id' => $voucherId, 'status' => 'paid']);
    }

    public function test_student_cannot_change_coupon_or_gift_voucher_admin_settings(): void
    {
        $student = $this->createUserWithRole('student');

        $this->actingAs($student)->put(route('plugins.marketing.gift-voucher-settings.update'), [
            'enabled' => '0',
            'minimum_amount' => 100,
            'maximum_amount' => 10000,
            'validity_days' => 30,
        ])->assertForbidden();

        $this->assertDatabaseMissing('system_settings', ['key' => 'course_commerce.gift_vouchers']);
    }

    public function test_admin_can_disable_coupon_without_deleting_redemption_history(): void
    {
        $admin = $this->createUserWithRole('admin');
        $coupon = PromoCoupon::query()->create([
            'code' => 'ADMIN-CONTROL-25',
            'discount_percent' => 25,
            'max_uses' => 100,
            'used_count' => 3,
            'expires_at' => now()->addDays(30)->toDateString(),
            'is_active' => true,
            'discount_type' => 'percent',
            'minimum_subtotal' => 0,
            'applies_to_course_ids' => [],
            'first_purchase_only' => false,
        ]);

        $this->actingAs($admin)->patch(route('plugins.marketing.coupons.update', $coupon->id), [
            'discount_type' => 'percent',
            'discount_percent' => 15,
            'max_uses' => 50,
            'minimum_subtotal' => 500,
            'is_active' => '0',
            'applies_to_course_ids' => [],
        ])->assertRedirect();

        $coupon->refresh();
        $this->assertFalse($coupon->is_active);
        $this->assertSame(15, $coupon->discount_percent);
        $this->assertSame(3, $coupon->used_count);
        $this->assertSame(50, $coupon->max_uses);
    }
}
