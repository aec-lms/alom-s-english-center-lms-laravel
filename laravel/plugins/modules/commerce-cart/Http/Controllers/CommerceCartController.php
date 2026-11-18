<?php

declare(strict_types=1);

namespace Plugins\\Modules\\CommerceCart\\Http\\Controllers;

use App\\Http\\Controllers\\Controller;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\View\\View;
use Plugins\\Modules\\CommerceCart\\Services\\CommerceCatalog;

class CommerceCartController extends Controller
{
    public function __construct(private readonly CommerceCatalog $catalog) {}

    public function index(Request $request): View
    {
        $cart = $this->activeCart((int) $request->user()->id);
        $items = DB::table('commerce_cart_items')->where('cart_id', $cart->id)->orderBy('id')->get();
        $subtotal = (float) $items->sum(fn ($item) => (float) $item->unit_price * (int) $item->quantity);
        return view('commerce-cart::cart', compact('cart', 'items', 'subtotal'));
    }

    public function add(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'item_type' => ['required', 'string', 'in:course,ebook,mock_test,exam,subscription,live_class,other'],
            'item_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        $item = $this->catalog->resolve($data['item_type'], (int) $data['item_id']);
        $cart = $this->activeCart((int) $request->user()->id);

        DB::transaction(function () use ($cart, $item, $data): void {
            $existing = DB::table('commerce_cart_items')->where('cart_id', $cart->id)
                ->where('item_type', $item['type'])->where('item_id', $item['id'])->lockForUpdate()->first();
            $quantity = min(20, (int) ($existing->quantity ?? 0) + (int) ($data['quantity'] ?? 1));
            if ($existing) {
                DB::table('commerce_cart_items')->where('id', $existing->id)->update([
                    'quantity' => $quantity, 'unit_price' => $item['price'],
                    'title_snapshot' => $item['title'], 'metadata' => json_encode($item['metadata']),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('commerce_cart_items')->insert([
                    'cart_id' => $cart->id, 'item_type' => $item['type'], 'item_id' => $item['id'],
                    'quantity' => (int) ($data['quantity'] ?? 1), 'unit_price' => $item['price'],
                    'currency' => $item['currency'], 'title_snapshot' => $item['title'],
                    'metadata' => json_encode($item['metadata']), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return redirect()->route('plugins.commerce_cart.index')->with('status', 'Added to your shopping cart.');
    }

    public function update(Request $request, int $item): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:20']]);
        $cart = $this->activeCart((int) $request->user()->id);
        $updated = DB::table('commerce_cart_items')->where('id', $item)->where('cart_id', $cart->id)
            ->update(['quantity' => $data['quantity'], 'updated_at' => now()]);
        abort_unless($updated, 404);
        return back()->with('status', 'Cart quantity updated.');
    }

    public function remove(Request $request, int $item): RedirectResponse
    {
        $cart = $this->activeCart((int) $request->user()->id);
        $deleted = DB::table('commerce_cart_items')->where('id', $item)->where('cart_id', $cart->id)->delete();
        abort_unless($deleted, 404);
        return back()->with('status', 'Item removed from cart.');
    }

    private function activeCart(int $userId): object
    {
        return DB::transaction(function () use ($userId): object {
            $cart = DB::table('commerce_carts')->where('user_id', $userId)->where('status', 'active')->lockForUpdate()->first();
            if ($cart) return $cart;
            $id = DB::table('commerce_carts')->insertGetId([
                'user_id' => $userId, 'currency' => strtoupper((string) config('lms.default_currency', 'BDT')),
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            return DB::table('commerce_carts')->where('id', $id)->first();
        });
    }
}
