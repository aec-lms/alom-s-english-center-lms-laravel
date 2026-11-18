<?php

declare(strict_types=1);

namespace Plugins\Modules\CommerceCart\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Plugins\Modules\CommerceCart\Services\CommerceCatalog;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\UniversalPayment\Services\UniversalPaymentOrchestrator;
use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CommerceCartController extends Controller
{
    public function __construct(private readonly CommerceCatalog $catalog) {}


    public function publicServices(): View
    {
        $services = DB::table('commerce_sellables')->where('is_active', true)
            ->whereIn('type', ['subscription', 'live_class', 'other'])
            ->where(fn ($query) => $query->whereNull('available_from')->orWhere('available_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('available_until')->orWhere('available_until', '>', now()))
            ->orderBy('type')->orderBy('title')->get();
        return view('commerce-cart::services', compact('services'));
    }

    public function index(Request $request): View
    {
        $cart = $this->activeCart((int) $request->user()->id);
        $items = DB::table('commerce_cart_items')->where('cart_id', $cart->id)->orderBy('id')->get();
        $subtotal = (float) $items->sum(fn ($item) => (float) $item->unit_price * (int) $item->quantity);
        return view('commerce-cart::cart', compact('cart', 'items', 'subtotal'));
    }

    public function add(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('student'), 403, 'Only student accounts can add learning products to the shopping cart.');
        $data = $request->validate([
            'item_type' => ['required', 'string', 'in:course,ebook,mock_test,exam,subscription,live_class,other'],
            'item_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        $item = $this->catalog->resolve($data['item_type'], (int) $data['item_id']);
        if ($item['type'] !== 'other' && (int) ($data['quantity'] ?? 1) !== 1) {
            return back()->withErrors(['quantity' => 'Course access, eBooks, exams, subscriptions and live-class passes can only be purchased once per order.']);
        }
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



    public function orders(): View
    {
        abort_unless(auth()->user()?->can('commerce.orders.manage'), 403);
        $orders = DB::table('commerce_orders')
            ->join('users', 'users.id', '=', 'commerce_orders.user_id')
            ->select('commerce_orders.*', 'users.name as customer_name', 'users.email as customer_email')
            ->latest('commerce_orders.id')->paginate(30);
        return view('commerce-cart::orders', compact('orders'));
    }

    public function sellables(): View
    {
        abort_unless(auth()->user()?->can('commerce.orders.manage'), 403);
        $sellables = DB::table('commerce_sellables')->latest('id')->paginate(30);
        return view('commerce-cart::sellables', compact('sellables'));
    }

    public function storeSellable(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('commerce.orders.manage'), 403);
        $data = $request->validate([
            'type' => ['required', 'in:subscription,live_class,other'],
            'title' => ['required', 'string', 'max:200'],
            'sku' => ['nullable', 'string', 'max:80'],
            'resource_id' => ['required_if:type,live_class', 'nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:3000'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:500000'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if ($data['type'] === 'live_class') {
            abort_unless(Schema::hasTable('live_classes')
                && DB::table('live_classes')->where('id', (int) $data['resource_id'])->exists(),
                422,
                'Select an existing Live Class before publishing a paid pass.');
        }
        $entitlement = ['type' => $data['type']];
        if ($data['type'] === 'subscription' || !empty($data['duration_days'])) $entitlement['duration_days'] = (int) ($data['duration_days'] ?? 30);
        DB::table('commerce_sellables')->insert([
            'type' => $data['type'], 'title' => $data['title'], 'sku' => $data['sku'] ?? null,
            'resource_id' => $data['resource_id'] ?? null,
            'description' => $data['description'] ?? null, 'price' => $data['price'],
            'currency' => strtoupper($data['currency']), 'entitlement' => json_encode($entitlement),
            'is_active' => $request->boolean('is_active', true),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return back()->with('status', 'Paid service added to the unified store catalogue.');
    }

    public function toggleSellable(Request $request, int $sellable): RedirectResponse
    {
        abort_unless($request->user()?->can('commerce.orders.manage'), 403);
        $record = DB::table('commerce_sellables')->where('id', $sellable)->first();
        abort_unless($record, 404);
        DB::table('commerce_sellables')->where('id', $sellable)->update(['is_active' => !$record->is_active, 'updated_at' => now()]);
        return back()->with('status', 'Store catalogue availability updated.');
    }

    public function checkout(Request $request, UniversalPaymentOrchestrator $payments): RedirectResponse
    {
        $data = $request->validate([
            'gateway' => ['required', 'in:gateway-sslcommerz,gateway-stripe,gateway-paypal'],
            'payer_name' => ['required', 'string', 'max:160'],
            'payer_email' => ['required', 'email', 'max:180'],
            'payer_phone' => ['required', 'string', 'max:40'],
            'payment_method_id' => ['nullable', 'string', 'max:120'],
        ]);
        abort_unless(Schema::hasTable('fee_invoices') && app(PluginManager::class)->isEnabled('fees-management'), 503, 'The Fees & Invoicing module must be enabled for checkout.');
        abort_unless(app(\App\Core\Plugins\PluginManager::class)->isEnabled('universal-payment'), 503, 'Universal Payment must be enabled.');

        try {
            $payments->assertGatewayReady($data['gateway']);
        } catch (\Throwable $exception) {
            return back()->withErrors(['gateway' => $exception->getMessage()])->withInput();
        }

        $user = $request->user();
        $unresolvedOrder = DB::table('commerce_orders')->where('user_id', $user->id)
            ->whereIn('status', ['awaiting_payment', 'risk_review'])
            ->whereIn('payment_status', ['unpaid', 'pending', 'risk_review'])
            ->exists();
        abort_if($unresolvedOrder, 409, 'A previous order is awaiting payment confirmation or reconciliation. Please contact support before starting another checkout.');
        $cart = $this->activeCart((int) $user->id);
        $cartItems = DB::table('commerce_cart_items')->where('cart_id', $cart->id)->lockForUpdate()->get();
        abort_if($cartItems->isEmpty(), 422, 'Your cart is empty.');

        $resolved = [];
        foreach ($cartItems as $cartItem) {
            $fresh = $this->catalog->resolve((string) $cartItem->item_type, (int) $cartItem->item_id);
            if ($fresh['type'] !== 'other' && (int) $cartItem->quantity !== 1) {
                abort(422, 'Access-based products must have quantity one. Remove the duplicate quantity and try again.');
            }
            if ((float) $fresh['price'] !== (float) $cartItem->unit_price) {
                DB::table('commerce_cart_items')->where('id', $cartItem->id)->update([
                    'unit_price' => $fresh['price'], 'title_snapshot' => $fresh['title'], 'updated_at' => now(),
                ]);
            }
            $resolved[] = [$cartItem, $fresh];
        }
        $currencies = collect($resolved)->pluck(1)->pluck('currency')->unique();
        abort_unless($currencies->count() === 1, 422, 'A single order cannot mix currencies.');
        $subtotal = round(collect($resolved)->sum(fn ($pair) => $pair[1]['price'] * (int) $pair[0]->quantity), 2);
        abort_if($subtotal <= 0, 422, 'The checkout total must be greater than zero.');

        $order = DB::transaction(function () use ($user, $cart, $resolved, $subtotal, $data, $currencies): object {
            $invoice = FeeInvoice::query()->create([
                'invoice_number' => 'WEB-' . strtoupper(Str::random(10)),
                'student_id' => null,
                'payer_name' => $data['payer_name'],
                'payer_email' => strtolower($data['payer_email']),
                'payer_phone' => $data['payer_phone'],
                'branch_id' => $user->branch_id ?? null,
                'subtotal_amount' => $subtotal,
                'discount_amount' => 0,
                'net_amount' => $subtotal,
                'paid_amount' => 0,
                'currency' => $currencies->first(),
                'status' => 'unpaid',
                'due_date' => now()->addDays(7)->toDateString(),
            ]);
            $orderId = DB::table('commerce_orders')->insertGetId([
                'order_number' => 'AEC-' . strtoupper(Str::random(10)), 'user_id' => $user->id,
                'invoice_id' => $invoice->id, 'branch_id' => $user->branch_id ?? null,
                'currency' => $currencies->first(), 'subtotal' => $subtotal, 'discount_total' => 0,
                'grand_total' => $subtotal, 'status' => 'awaiting_payment', 'payment_status' => 'unpaid',
                'billing_snapshot' => json_encode(['name' => $data['payer_name'], 'email' => $data['payer_email'], 'phone' => $data['payer_phone']]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($resolved as [$cartItem, $fresh]) {
                $orderItemId = DB::table('commerce_order_items')->insertGetId([
                    'order_id' => $orderId, 'item_type' => $fresh['type'], 'item_id' => $fresh['id'],
                    'title_snapshot' => $fresh['title'], 'quantity' => $cartItem->quantity,
                    'unit_price' => $fresh['price'], 'line_total' => round($fresh['price'] * $cartItem->quantity, 2),
                    'metadata' => json_encode($fresh['metadata']), 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($fresh['type'] === 'course' && Schema::hasTable('enrollments')
                    && Schema::hasColumn('enrollments', 'invoice_id')) {
                    $existingEnrollment = DB::table('enrollments')->where('user_id', $user->id)
                        ->where('course_id', $fresh['id'])->lockForUpdate()->first();
                    if ($existingEnrollment && in_array($existingEnrollment->status, ['active', 'completed'], true)) {
                        throw new \RuntimeException('You already have access to course: ' . $fresh['title']);
                    }
                    if ($existingEnrollment && $existingEnrollment->status === 'pending') {
                        DB::table('enrollments')->where('id', $existingEnrollment->id)->update([
                            'invoice_id' => $invoice->id, 'updated_at' => now(),
                        ]);
                    } elseif (!$existingEnrollment) {
                        DB::table('enrollments')->insert([
                            'user_id' => $user->id, 'course_id' => $fresh['id'], 'branch_id' => $user->branch_id ?? null,
                            'invoice_id' => $invoice->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }
            DB::table('commerce_carts')->where('id', $cart->id)->update(['status' => 'converted', 'updated_at' => now()]);
            return DB::table('commerce_orders')->where('id', $orderId)->first();
        });

        try {
            $payment = $payments->initiate($data['gateway'], FeeInvoice::query()->findOrFail($order->invoice_id), [
                'payment_method_id' => $data['payment_method_id'] ?? '',
                'idempotency_key' => 'aec-order-' . $order->order_number,
            ]);
            DB::table('commerce_orders')->where('id', $order->id)->update(['payment_status' => 'pending', 'updated_at' => now()]);
            if (!empty($payment['redirect_url'])) return redirect()->away($payment['redirect_url']);
            return redirect()->route('plugins.commerce_cart.index')
                ->with('status', 'Order ' . $order->order_number . ' is awaiting payment confirmation. Follow the secure payment instructions shown by your selected gateway.');
        } catch (\Throwable $exception) {
            report($exception);
            $ambiguous = $exception instanceof ConnectionException;
            DB::table('commerce_orders')->where('id', $order->id)->update([
                'status' => $ambiguous ? 'risk_review' : 'failed',
                'payment_status' => $ambiguous ? 'risk_review' : 'failed',
                'updated_at' => now(),
            ]);
            if (!$ambiguous) {
                DB::table('commerce_carts')->where('id', $cart->id)->update(['status' => 'active', 'updated_at' => now()]);
            }
            return redirect()->route('plugins.commerce_cart.index')
                ->withErrors(['gateway' => $ambiguous
                    ? 'The gateway response was ambiguous. Do not retry yet; contact support with order ' . $order->order_number . ' for reconciliation.'
                    : 'The gateway could not start checkout. Your cart is available to retry after the issue is corrected. No access has been granted.']);
        }
    }

    private function activeCart(int $userId): object
    {
        return DB::transaction(function () use ($userId): object {
            abort_unless(DB::table('users')->where('id', $userId)->lockForUpdate()->first(), 404);
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
