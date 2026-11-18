<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class UnifiedCommerceCartTest extends TestCase
{
    public function test_student_can_add_subscription_and_live_class_pass_to_one_cart_using_server_prices(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['commerce-cart']);
        $student = $this->createUserWithRole('student');

        $subscriptionId = DB::table('commerce_sellables')->insertGetId([
            'type' => 'subscription', 'title' => 'Premium Monthly', 'sku' => 'PREMIUM-30',
            'description' => 'Monthly access', 'price' => 499, 'currency' => 'BDT',
            'entitlement' => json_encode(['type' => 'subscription', 'duration_days' => 30]),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $livePassId = DB::table('commerce_sellables')->insertGetId([
            'type' => 'live_class', 'title' => 'Live Speaking Workshop', 'sku' => 'LIVE-01',
            'description' => 'Speaking workshop access', 'price' => 250, 'currency' => 'BDT',
            'entitlement' => json_encode(['type' => 'live_class']),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'subscription', 'item_id' => $subscriptionId, 'quantity' => 1, 'unit_price' => 1,
        ])->assertRedirect('/cart');

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
}
