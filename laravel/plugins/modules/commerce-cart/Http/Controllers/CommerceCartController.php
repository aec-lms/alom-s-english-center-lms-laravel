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
use Plugins\Modules\MarketingManagement\Services\CourseCouponService;
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
        abort_unless($request->user()?->hasRole('student'), 403);
        $cart = $this->activeCart((int) $request->user()->id);
        $items = DB::table('commerce_cart_items')->where('cart_id', $cart->id)->orderBy('id')->get();
        $subtotal = (float) $items->sum(fn ($item) => (float) $item->unit_price * (int) $item->quantity);
        $couponAvailable = app(PluginManager::class)->isEnabled('marketing-management')
            && Schema::hasTable('promo_coupons') && Schema::hasTable('promo_coupon_redemptions');
        return view('commerce-cart::cart', compact('cart', 'items', 'subtotal', 'couponAvailable'));
    }

    public function add(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('student'), 403, 'Only student accounts can add learning products to the shopping cart.');
        $data = $request->validate([
            'item_type' => ['required', 'string', 'in:course,ebook,mock_test,exam,subscription,live_class,other'],
            'item_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        $item = $this->catalog->resolve($data['item_type'], (int) $data['item_id'], $request->user());
        if ($item['type'] !== 'other' && (int) ($data['quantity'] ?? 1) !== 1) {
            return back()->withErrors(['quantity' => 'Course access, eBooks, exams, subscriptions and live-class passes can only be purchased once per order.']);
        }
        $cart = $this->activeCart((int) $request->user()->id);

        DB::transaction(function () use ($cart, $item, $data, $request): void {
            $lockedCart = DB::table('commerce_carts')->where('id', $cart->id)
                ->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($lockedCart, 409, 'This cart has already been checked out. Start a new cart before adding more items.');
            $existing = DB::table('commerce_cart_items')->where('cart_id', $lockedCart->id)
                ->where('item_type', $item['type'])->where('item_id', $item['id'])->lockForUpdate()->first();
            // Access grants are single-quantity products. Repeated Add to Cart
            // requests must remain idempotent instead of creating an invalid quantity.
            $quantity = $item['type'] === 'other'
                ? min(20, (int) ($existing->quantity ?? 0) + (int) ($data['quantity'] ?? 1))
                : 1;
            if ($existing) {
                DB::table('commerce_cart_items')->where('id', $existing->id)->update([
                    'quantity' => $quantity, 'unit_price' => $item['price'],
                    'currency' => $item['currency'],
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
        abort_unless($request->user()?->hasRole('student'), 403);
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:20']]);
        $cart = $this->activeCart((int) $request->user()->id);

        DB::transaction(function () use ($request, $cart, $item, $data): void {
            $lockedCart = DB::table('commerce_carts')->where('id', $cart->id)
                ->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($lockedCart, 409, 'This cart has already been checked out.');
            $cartItem = DB::table('commerce_cart_items')->where('id', $item)->where('cart_id', $lockedCart->id)->lockForUpdate()->first();
            abort_unless($cartItem, 404);

            if ($cartItem->item_type !== 'other' && (int) $data['quantity'] !== 1) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'quantity' => 'Course access, eBooks, exams, subscriptions and live-class passes can only be purchased once per order.',
                ]);
            }

            DB::table('commerce_cart_items')->where('id', $item)->where('cart_id', $lockedCart->id)
                ->update(['quantity' => $data['quantity'], 'updated_at' => now()]);
        });
        return back()->with('status', 'Cart quantity updated.');
    }

    public function remove(Request $request, int $item): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('student'), 403);
        $cart = $this->activeCart((int) $request->user()->id);
        DB::transaction(function () use ($request, $cart, $item): void {
            $lockedCart = DB::table('commerce_carts')->where('id', $cart->id)
                ->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($lockedCart, 409, 'This cart has already been checked out.');
            $deleted = DB::table('commerce_cart_items')->where('id', $item)->where('cart_id', $lockedCart->id)->delete();
            abort_unless($deleted, 404);
        });
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
        abort_unless($request->user()?->hasRole('student'), 403);
        $data = $request->validate([
            'gateway' => ['required', 'in:gateway-sslcommerz,gateway-stripe,gateway-paypal'],
            'payer_name' => ['required', 'string', 'max:160'],
            'payer_email' => ['required', 'email', 'max:180'],
            'payer_phone' => ['required', 'string', 'max:40'],
            'payment_method_id' => ['nullable', 'string', 'max:120'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);
        abort_unless(Schema::hasTable('fee_invoices') && app(PluginManager::class)->isEnabled('fees-management'), 503, 'The Fees & Invoicing module must be enabled for checkout.');
        abort_unless(app(PluginManager::class)->isEnabled('universal-payment'), 503, 'Universal Payment must be enabled.');
        abort_unless(app(PluginManager::class)->isEnabled('payment-management')
            && Schema::hasTable('payment_transactions'), 503,
            'Payment Management must be enabled before unified commerce checkout.');

        try {
            $payments->assertGatewayReady($data['gateway']);
        } catch (\Throwable $exception) {
            return back()->withErrors(['gateway' => $exception->getMessage()])->withInput();
        }

        $user = $request->user();
        $cart = $this->activeCart((int) $user->id);

        // Lock and re-read the cart and every item in one transaction. Never
        // create an order from a stale cart snapshot captured before the lock.
        $order = DB::transaction(function () use ($user, $cart, $data): object {
            abort_unless(DB::table('users')->where('id', $user->id)->lockForUpdate()->first(), 404);
            $hasUnresolvedOrder = DB::table('commerce_orders')->where('user_id', $user->id)
                ->whereIn('status', ['awaiting_payment', 'risk_review'])
                ->whereIn('payment_status', ['unpaid', 'pending', 'risk_review'])
                ->exists();
            abort_if($hasUnresolvedOrder, 409, 'A previous order is awaiting payment confirmation or reconciliation.');

            $lockedCart = DB::table('commerce_carts')->where('id', $cart->id)
                ->where('user_id', $user->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($lockedCart, 409, 'Your cart has already been checked out. Refresh the page before trying again.');

            $cartItems = DB::table('commerce_cart_items')->where('cart_id', $lockedCart->id)
                ->orderBy('id')->lockForUpdate()->get();
            abort_if($cartItems->isEmpty(), 422, 'Your cart is empty.');

            $resolved = [];
            foreach ($cartItems as $cartItem) {
                $fresh = $this->catalog->resolve((string) $cartItem->item_type, (int) $cartItem->item_id, $user);
                if ($fresh['type'] !== 'other' && (int) $cartItem->quantity !== 1) {
                    abort(422, 'Access-based products must have quantity one. Remove the duplicate quantity and try again.');
                }
                $this->catalog->assertCartSnapshotUnchanged($fresh, $cartItem);
                $resolved[] = [$cartItem, $fresh];
            }

            $currencies = collect($resolved)->pluck(1)->pluck('currency')->unique();
            abort_unless($currencies->count() === 1, 422, 'A single order cannot mix currencies.');
            $subtotal = round(collect($resolved)->sum(fn ($pair) => $pair[1]['price'] * (int) $pair[0]->quantity), 2);
            abort_if($subtotal <= 0, 422, 'The checkout total must be greater than zero.');

            $coupon = null;
            if (filled($data['coupon_code'] ?? null)) {
                abort_unless(app(PluginManager::class)->isEnabled('marketing-management')
                    && Schema::hasTable('promo_coupons') && Schema::hasTable('promo_coupon_redemptions'),
                    503, 'Course coupon support is not currently available.');

                $courseItems = collect($resolved)->filter(fn ($pair) => $pair[1]['type'] === 'course');
                $courseIds = $courseItems->map(fn ($pair) => (int) $pair[1]['id'])->values()->all();
                $courseSubtotal = round($courseItems->sum(fn ($pair) => $pair[1]['price'] * (int) $pair[0]->quantity), 2);
                abort_if($courseIds === [] || $courseSubtotal <= 0, 422, 'Course coupons can only be used when your cart contains an eligible paid course.');

                $coupon = app(CourseCouponService::class)->reserve(
                    (string) $data['coupon_code'],
                    (int) $user->id,
                    $courseIds,
                    $courseSubtotal
                );
            }

            $discount = round((float) ($coupon['discount'] ?? 0), 2);
            $netAmount = round($subtotal - $discount, 2);
            abort_if($netAmount <= 0, 422, 'A coupon cannot reduce the order total to zero. Please use a different coupon.');

            $branchId = $this->assignedBranchId($user);
            $invoice = FeeInvoice::query()->create([
                'invoice_number' => 'WEB-' . strtoupper(Str::random(10)),
                'student_id' => null,
                'payer_name' => $data['payer_name'],
                'payer_email' => strtolower($data['payer_email']),
                'payer_phone' => $data['payer_phone'],
                'branch_id' => $branchId,
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'net_amount' => $netAmount,
                'paid_amount' => 0,
                'currency' => $currencies->first(),
                'status' => 'unpaid',
                'due_date' => now()->addDays(7)->toDateString(),
            ]);
            $orderId = DB::table('commerce_orders')->insertGetId([
                'order_number' => 'AEC-' . strtoupper(Str::random(10)),
                'user_id' => $user->id,
                'invoice_id' => $invoice->id,
                'branch_id' => $branchId,
                'currency' => $currencies->first(),
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'grand_total' => $netAmount,
                'coupon_id' => $coupon['coupon_id'] ?? null,
                'coupon_code' => $coupon['code'] ?? null,
                'coupon_reservation_key' => $coupon['reservation_key'] ?? null,
                'status' => 'awaiting_payment',
                'payment_status' => 'unpaid',
                'billing_snapshot' => json_encode(['name' => $data['payer_name'], 'email' => $data['payer_email'], 'phone' => $data['payer_phone']]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($resolved as [$cartItem, $fresh]) {
                DB::table('commerce_order_items')->insert([
                    'order_id' => $orderId,
                    'item_type' => $fresh['type'],
                    'item_id' => $fresh['id'],
                    'title_snapshot' => $fresh['title'],
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $fresh['price'],
                    'line_total' => round($fresh['price'] * $cartItem->quantity, 2),
                    'metadata' => json_encode($fresh['metadata']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($fresh['type'] === 'course' && Schema::hasTable('enrollments')
                    && Schema::hasColumn('enrollments', 'invoice_id')) {
                    $existingEnrollment = DB::table('enrollments')->where('user_id', $user->id)
                        ->where('course_id', $fresh['id'])->lockForUpdate()->first();
                    if ($existingEnrollment && in_array($existingEnrollment->status, ['active', 'completed'], true)) {
                        throw new \RuntimeException('You already have access to course: ' . $fresh['title']);
                    }
                    if ($existingEnrollment && $existingEnrollment->status === 'pending') {
                        if (isset($existingEnrollment->branch_id) && $existingEnrollment->branch_id !== null
                            && $branchId !== null && (int) $existingEnrollment->branch_id !== (int) $branchId) {
                            throw new \RuntimeException('An existing pending enrollment belongs to a different branch.');
                        }
                        DB::table('enrollments')->where('id', $existingEnrollment->id)->update([
                            'invoice_id' => $invoice->id,
                            'branch_id' => $branchId,
                            'updated_at' => now(),
                        ]);
                    } elseif (!$existingEnrollment) {
                        DB::table('enrollments')->insert([
                            'user_id' => $user->id,
                            'course_id' => $fresh['id'],
                            'branch_id' => $branchId,
                            'invoice_id' => $invoice->id,
                            'status' => 'pending',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            DB::table('commerce_carts')->where('id', $lockedCart->id)->update(['status' => 'converted', 'updated_at' => now()]);
            return DB::table('commerce_orders')->where('id', $orderId)->first();
        });

        try {
            $payment = $payments->initiate($data['gateway'], FeeInvoice::query()->findOrFail($order->invoice_id), [
                'payment_method_id' => $data['payment_method_id'] ?? '',
                'idempotency_key' => 'aec-order-' . $order->order_number,
            ]);
            DB::table('commerce_orders')->where('id', $order->id)->update(['payment_status' => 'pending', 'updated_at' => now()]);
            if (!empty($payment['redirect_url'])) {
                return redirect()->away($payment['redirect_url']);
            }
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
            if (!$ambiguous && filled($order->coupon_reservation_key ?? null) && Schema::hasTable('promo_coupon_redemptions')) {
                DB::table('promo_coupon_redemptions')
                    ->where('reservation_key', $order->coupon_reservation_key)
                    ->where('status', 'reserved')
                    ->update(['status' => 'released', 'updated_at' => now()]);
            }
            return redirect()->route('plugins.commerce_cart.index')
                ->withErrors(['gateway' => $ambiguous
                    ? 'The gateway response was ambiguous. Do not retry yet; contact support with order ' . $order->order_number . ' for reconciliation.'
                    : 'The gateway could not start checkout. No access was granted. You can add the items to a new cart and try again.']);
        }
    }

    private function assignedBranchId($user): ?int
    {
        if (app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'branch_id')) {
            $profileBranchId = DB::table('students')->where('user_id', $user->id)->value('branch_id');
            if ($profileBranchId !== null) {
                return (int) $profileBranchId;
            }
        }

        return $user->branch_id !== null ? (int) $user->branch_id : null;
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
