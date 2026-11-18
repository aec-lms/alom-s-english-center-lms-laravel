<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Validation\ValidationException;
use Plugins\Modules\MarketingManagement\Models\PromoCoupon;
use Plugins\Modules\MarketingManagement\Services\CourseCouponService;
use Tests\TestCase;

class CourseCouponServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markSystemInstalled();
        $this->activatePlugins(['marketing-management', 'course-management']);
    }

    public function test_percentage_coupon_reservation_redeems_once_even_when_callback_retries(): void
    {
        $student = $this->createUserWithRole('student');
        $coupon = $this->coupon(['discount_percent' => 20, 'max_uses' => 2]);
        $service = new CourseCouponService();

        $quote = $service->quote($coupon->code, (int) $student->id, [101, 102], 1000);
        $this->assertSame(200.0, $quote['discount']);
        $this->assertSame(800.0, $quote['total']);

        $reservation = $service->reserve($coupon->code, (int) $student->id, [101, 102], 1000);
        $service->redeem($reservation['reservation_key']);
        $service->redeem($reservation['reservation_key']);

        $this->assertSame(1, (int) $coupon->fresh()->used_count);
        $this->assertDatabaseHas('promo_coupon_redemptions', [
            'reservation_key' => $reservation['reservation_key'],
            'status' => 'redeemed',
        ]);
    }

    public function test_fixed_coupon_respects_maximum_discount_cap(): void
    {
        $student = $this->createUserWithRole('student');
        $coupon = $this->coupon([
            'discount_type' => 'fixed',
            'discount_percent' => 0,
            'discount_amount' => 350,
            'maximum_discount' => 200,
        ]);

        $quote = (new CourseCouponService())->quote($coupon->code, (int) $student->id, [201], 1000);
        $this->assertSame(200.0, $quote['discount']);
        $this->assertSame(800.0, $quote['total']);
    }

    public function test_zero_maximum_uses_means_unlimited(): void
    {
        $first = $this->createUserWithRole('student');
        $second = $this->createUserWithRole('student');
        $coupon = $this->coupon(['max_uses' => 0]);
        $service = new CourseCouponService();

        $one = $service->reserve($coupon->code, (int) $first->id, [301], 100);
        $two = $service->reserve($coupon->code, (int) $second->id, [302], 100);

        $this->assertNotSame($one['reservation_key'], $two['reservation_key']);
        $this->assertSame(0, (int) $coupon->fresh()->max_uses);
    }

    public function test_course_scope_and_per_student_limit_are_enforced(): void
    {
        $student = $this->createUserWithRole('student');
        $other = $this->createUserWithRole('student');
        $coupon = $this->coupon([
            'applies_to_course_ids' => [401],
            'per_user_limit' => 1,
        ]);
        $service = new CourseCouponService();

        $service->reserve($coupon->code, (int) $student->id, [401], 500);

        try {
            $service->reserve($coupon->code, (int) $student->id, [401], 500);
            $this->fail('The same student must not exceed the per-user coupon limit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coupon_code', $exception->errors());
        }

        try {
            $service->reserve($coupon->code, (int) $other->id, [402], 500);
            $this->fail('A coupon scoped to another course must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coupon_code', $exception->errors());
        }
    }

    public function test_releasing_a_reservation_frees_the_global_coupon_slot(): void
    {
        $first = $this->createUserWithRole('student');
        $second = $this->createUserWithRole('student');
        $coupon = $this->coupon(['max_uses' => 1]);
        $service = new CourseCouponService();

        $reservation = $service->reserve($coupon->code, (int) $first->id, [501], 100);
        $service->release($reservation['reservation_key']);

        $replacement = $service->reserve($coupon->code, (int) $second->id, [502], 100);
        $this->assertNotSame($reservation['reservation_key'], $replacement['reservation_key']);
        $this->assertSame(0, (int) $coupon->fresh()->used_count);
    }

    private function coupon(array $overrides = []): PromoCoupon
    {
        static $sequence = 0;
        $sequence++;

        return PromoCoupon::query()->create(array_merge([
            'code' => 'COURSE' . $sequence . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'discount_percent' => 10,
            'max_uses' => 10,
            'used_count' => 0,
            'expires_at' => now()->addDays(7)->toDateString(),
            'is_active' => true,
            'discount_type' => 'percent',
            'minimum_subtotal' => 0,
            'maximum_discount' => null,
            'per_user_limit' => null,
            'starts_at' => null,
            'applies_to_course_ids' => [],
            'first_purchase_only' => false,
        ], $overrides));
    }
}
