<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Plugins\Modules\CommerceCart\Services\CommerceCatalog;
use Tests\TestCase;

final class UnifiedCommerceCartTest extends TestCase
{

    public function test_checkout_rejects_catalogue_price_currency_or_title_changes_after_add_to_cart(): void
    {
        $catalog = app(CommerceCatalog::class);
        $cartItem = (object) [
            'unit_price' => 499,
            'currency' => 'BDT',
            'title_snapshot' => 'Premium Monthly',
        ];

        $catalog->assertCartSnapshotUnchanged([
            'price' => 499, 'currency' => 'BDT', 'title' => 'Premium Monthly',
        ], $cartItem);

        foreach ([
            ['price' => 599, 'currency' => 'BDT', 'title' => 'Premium Monthly'],
            ['price' => 499, 'currency' => 'USD', 'title' => 'Premium Monthly'],
            ['price' => 499, 'currency' => 'BDT', 'title' => 'Premium Annual'],
        ] as $changedSnapshot) {
            try {
                $catalog->assertCartSnapshotUnchanged($changedSnapshot, $cartItem);
                $this->fail('Checkout must reject a changed cart snapshot.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('cart', $exception->errors());
            }
        }
    }

    public function test_student_can_add_subscription_and_live_class_pass_to_one_cart_using_server_prices(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['commerce-cart', 'course-management']);
        $student = $this->createUserWithRole('student');

        $subscriptionId = DB::table('commerce_sellables')->insertGetId([
            'type' => 'subscription', 'title' => 'Premium Monthly', 'sku' => 'PREMIUM-30',
            'description' => 'Monthly access', 'price' => 499, 'currency' => 'BDT',
            'entitlement' => json_encode(['type' => 'subscription', 'duration_days' => 30]),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'CART-LIVE-01', 'title' => 'Live Pass Test Course',
            'slug' => 'live-pass-test-course', 'price' => 0, 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $liveClassId = DB::table('live_classes')->insertGetId([
            'course_id' => $courseId, 'title' => 'Live Speaking Workshop',
            'meeting_url' => 'https://example.test/live', 'starts_at' => now()->subMinutes(5),
            'status' => 'live', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $livePassId = DB::table('commerce_sellables')->insertGetId([
            'type' => 'live_class', 'title' => 'Live Speaking Workshop', 'sku' => 'LIVE-01',
            'description' => 'Speaking workshop access', 'price' => 250, 'currency' => 'BDT', 'resource_id' => $liveClassId,
            'entitlement' => json_encode(['type' => 'live_class']),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'subscription', 'item_id' => $subscriptionId, 'quantity' => 1, 'unit_price' => 1,
        ])->assertRedirect('/cart');

        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'subscription', 'item_id' => $subscriptionId, 'quantity' => 1,
        ])->assertRedirect('/cart');

        $subscriptionCartItem = DB::table('commerce_cart_items')
            ->where('item_type', 'subscription')->where('item_id', $subscriptionId)->first();
        $this->assertNotNull($subscriptionCartItem);
        $this->assertSame(1, (int) $subscriptionCartItem->quantity);

        $this->actingAs($student)->patch('/cart/items/' . $subscriptionCartItem->id, [
            'quantity' => 2,
        ])->assertSessionHasErrors('quantity');
        $this->assertDatabaseHas('commerce_cart_items', [
            'id' => $subscriptionCartItem->id, 'quantity' => 1,
        ]);

        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'live_class', 'item_id' => $livePassId, 'quantity' => 1,
        ])->assertRedirect('/cart');

        $cart = DB::table('commerce_carts')->where('user_id', $student->id)->where('status', 'active')->first();
        $this->assertNotNull($cart);
        $this->assertDatabaseCount('commerce_cart_items', 2);
        $this->assertDatabaseHas('commerce_cart_items', [
            'cart_id' => $cart->id, 'item_type' => 'subscription', 'item_id' => $subscriptionId, 'unit_price' => 499,
        ]);
        $this->assertDatabaseHas('commerce_cart_items', [
            'cart_id' => $cart->id, 'item_type' => 'live_class', 'item_id' => $livePassId, 'unit_price' => 250,
        ]);
        $this->actingAs($student)->get('/cart')->assertOk()->assertSee('Premium Monthly')->assertSee('Live Speaking Workshop');
    }

    public function test_inactive_service_cannot_be_added_to_cart(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['commerce-cart']);
        $student = $this->createUserWithRole('student');
        $id = DB::table('commerce_sellables')->insertGetId([
            'type' => 'subscription', 'title' => 'Disabled Plan', 'price' => 199, 'currency' => 'BDT',
            'entitlement' => '{}', 'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'subscription', 'item_id' => $id,
        ])->assertSessionHasErrors('item');

        $this->assertDatabaseCount('commerce_cart_items', 0);
    }

    public function test_cart_is_private_to_each_authenticated_student(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['commerce-cart']);
        $first = $this->createUserWithRole('student');
        $second = $this->createUserWithRole('student');
        $id = DB::table('commerce_sellables')->insertGetId([
            'type' => 'other', 'title' => 'Grammar Workshop', 'price' => 150, 'currency' => 'BDT',
            'entitlement' => '{}', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($first)->post('/cart/items', ['item_type' => 'other', 'item_id' => $id])->assertRedirect('/cart');
        $this->actingAs($second)->get('/cart')->assertOk()->assertDontSee('Grammar Workshop');
    }

    public function test_student_cannot_add_a_course_owned_by_another_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'commerce-cart']);
        $branchA = DB::table('branches')->insertGetId([
            'code' => 'CART-A-' . uniqid(), 'name' => 'Cart Branch A', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'CART-B-' . uniqid(), 'name' => 'Cart Branch B', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = $this->createUserWithRole('student', (int) $branchA);
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'CART-CROSS-' . strtoupper(uniqid()),
            'title' => 'Other Branch Paid Course',
            'slug' => 'other-branch-paid-course-' . uniqid(),
            'price' => 350,
            'status' => 'published',
            'branch_id' => $branchB,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'course', 'item_id' => $courseId, 'quantity' => 1,
        ])->assertSessionHasErrors('item');

        $this->assertDatabaseCount('commerce_cart_items', 0);
    }

    public function test_branch_manager_cannot_access_global_commerce_order_administration(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'commerce-cart']);
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'CART-MGR-' . uniqid(), 'name' => 'Cart Manager Branch', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', (int) $branchId);
        $role = Role::query()->where('slug', 'branch_manager')->firstOrFail();
        $permission = Permission::query()->where('slug', 'commerce.orders.manage')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($manager)->get('/admin/commerce/orders')->assertForbidden();
    }
}
