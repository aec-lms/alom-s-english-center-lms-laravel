<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\MarketingManagement\Services\CourseCouponService;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Plugins\Modules\UniversalPayment\Services\StorefrontPaymentGatewayService;
use RuntimeException;

class CourseCommerceController extends Controller
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function cart(Request $request): View
    {
        $student = $this->student($request);
        $cart = DB::table('course_carts')->where('user_id', $student->id)->where('status', 'active')->latest('id')->first();
        $items = $cart
            ? DB::table('course_cart_items')
                ->join('courses', 'courses.id', '=', 'course_cart_items.course_id')
                ->where('course_cart_items.cart_id', $cart->id)
                ->orderBy('course_cart_items.id')
                ->get([
                    'course_cart_items.id as cart_item_id',
                    'course_cart_items.course_id',
                    'course_cart_items.unit_price',
                    'courses.title',
                    'courses.slug',
                    'courses.price as current_price',
                    'courses.status as course_status',
                ])
            : collect();
        $subtotal = round((float) $items->sum(fn ($item) => (float) $item->unit_price), 2);
        $currency = strtoupper((string) ($cart->currency ?? config('lms.default_currency', 'BDT')));

        return view('course-management::commerce.cart', compact('cart', 'items', 'subtotal', 'currency'));
    }

    public function add(Request $request, int $courseId): RedirectResponse
    {
        $student = $this->student($request);
        abort_unless(Schema::hasTable('course_carts') && Schema::hasTable('course_cart_items'), 503, 'Course cart storage is not installed.');
        $course = DB::table('courses')->where('id', $courseId)->where('status', 'published')->first();
        abort_unless($course, 404, 'This course is not available for purchase.');
        $this->assertCourseBranch($student, $course);
        $this->assertNoOpenBatch($courseId);

        abort_if(DB::table('enrollments')->where('user_id', $student->id)->where('course_id', $courseId)
            ->whereIn('status', ['pending', 'active'])->exists(), 409, 'You already have a pending or active enrollment in this course.');

        DB::transaction(function () use ($student, $course): void {
            DB::table('users')->where('id', $student->id)->lockForUpdate()->first();
            $cart = DB::table('course_carts')->where('user_id', $student->id)->where('status', 'active')->lockForUpdate()->first();
            if (!$cart) {
                $cartId = DB::table('course_carts')->insertGetId([
                    'user_id' => $student->id,
                    'status' => 'active',
                    'currency' => strtoupper((string) config('lms.default_currency', 'BDT')),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $cartId = (int) $cart->id;
            }

            $existing = DB::table('course_cart_items')->where('cart_id', $cartId)->where('course_id', $course->id)->first();
            if ($existing) {
                DB::table('course_cart_items')->where('id', $existing->id)->update([
                    'unit_price' => round((float) $course->price, 2),
                    'updated_at' => now(),
                ]);
                return;
            }

            DB::table('course_cart_items')->insert([
                'cart_id' => $cartId,
                'course_id' => $course->id,
                'unit_price' => round((float) $course->price, 2),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);

        return redirect()->route('course-commerce.cart')->with('status', 'Course added to your cart.');
    }

    public function remove(Request $request, int $item): RedirectResponse
    {
        $student = $this->student($request);
        $cart = DB::table('course_carts')->where('user_id', $student->id)->where('status', 'active')->first();
        abort_unless($cart, 404);
        DB::table('course_cart_items')->where('id', $item)->where('cart_id', $cart->id)->delete();

        return redirect()->route('course-commerce.cart')->with('status', 'Course removed from your cart.');
    }

    public function checkout(Request $request): RedirectResponse
    {
        $student = $this->student($request);
        $validated = $request->validate([
            'payer_contact' => ['required', 'string', 'min:7', 'max:40'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);
        $this->assertCheckoutReady();

        $couponService = new CourseCouponService();
        $created = DB::transaction(function () use ($student, $validated, $couponService): array {
            DB::table('users')->where('id', $student->id)->lockForUpdate()->first();
            $cart = DB::table('course_carts')->where('user_id', $student->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($cart, 422, 'Your course cart is empty.');

            $cartItems = DB::table('course_cart_items')->where('cart_id', $cart->id)->orderBy('id')->lockForUpdate()->get();
            abort_if($cartItems->isEmpty(), 422, 'Your course cart is empty.');
            $courseIds = $cartItems->pluck('course_id')->map(fn ($id): int => (int) $id)->all();
            $courses = DB::table('courses')->whereIn('id', $courseIds)->where('status', 'published')->get()->keyBy('id');
            abort_unless($courses->count() === count($courseIds), 409, 'A course in your cart is no longer available. Review your cart and try again.');

            $branchIds = $courses->pluck('branch_id')->filter()->unique();
            abort_if($branchIds->count() > 1, 422, 'Courses from different branches cannot be purchased in one cart.');
            $branchId = $branchIds->first() ?? $student->branch_id;
            if ($branchId !== null) {
                abort_unless($student->branch_id !== null && (int) $branchId === (int) $student->branch_id, 403, 'Your cart contains a course outside your assigned branch.');
            }

            foreach ($cartItems as $cartItem) {
                $course = $courses->get($cartItem->course_id);
                abort_if(abs((float) $course->price - (float) $cartItem->unit_price) > 0.01, 409, 'A course price changed. Review your cart before paying.');
                $this->assertNoOpenBatch((int) $course->id);
                abort_if(DB::table('enrollments')->where('user_id', $student->id)->where('course_id', $course->id)
                    ->whereIn('status', ['pending', 'active'])->exists(), 409, 'You already have a pending or active enrollment in one of the selected courses.');
            }

            abort_unless(Schema::hasColumn('enrollments', 'invoice_id'), 503, 'Paid course enrollment requires invoice-linked enrollment support.');
            $subtotal = round($cartItems->sum(fn ($item) => (float) $item->unit_price), 2);
            $currency = strtoupper((string) ($cart->currency ?: config('lms.default_currency', 'BDT')));
            $coupon = null;
            if (filled($validated['coupon_code'] ?? null)) {
                $coupon = $couponService->reserve((string) $validated['coupon_code'], (int) $student->id, $courseIds, $subtotal);
            }
            $discount = round((float) ($coupon['discount'] ?? 0), 2);
            $total = round(max(0, $subtotal - $discount), 2);

            $orderId = DB::table('course_cart_orders')->insertGetId([
                'cart_id' => $cart->id,
                'user_id' => $student->id,
                'branch_id' => $branchId,
                'coupon_id' => $coupon['coupon_id'] ?? null,
                'coupon_code' => $coupon['code'] ?? null,
                'coupon_reservation_key' => $coupon['reservation_key'] ?? null,
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'net_amount' => $total,
                'currency' => $currency,
                'status' => $total > 0 ? 'awaiting_payment' : 'processing',
                'expires_at' => now()->addMinutes(30),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($cartItems as $position => $cartItem) {
                $course = $courses->get($cartItem->course_id);
                DB::table('course_cart_order_items')->insert([
                    'order_id' => $orderId,
                    'course_id' => $course->id,
                    'course_title' => $course->title,
                    'unit_price' => round((float) $cartItem->unit_price, 2),
                    'position' => $position + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('course_carts')->where('id', $cart->id)->update(['status' => 'checkout_pending', 'updated_at' => now()]);

            if ($total <= 0) {
                $this->activateOrder($orderId, $student, $branchId, $couponService);
                return ['order_id' => $orderId, 'transaction' => null, 'coupon_key' => $coupon['reservation_key'] ?? null, 'free' => true];
            }

            $transaction = UniversalPaymentTransaction::query()->create([
                'reference' => 'COURSE-' . strtoupper(Str::random(10)),
                'payer_name' => $student->name,
                'payer_contact' => trim($validated['payer_contact']),
                'item_title' => 'Course Cart Order #' . $orderId,
                'gateway' => 'sslcommerz',
                'currency' => $currency,
                'amount' => $total,
                'external_trx_id' => null,
                'user_id' => $student->id,
                'branch_id' => $branchId,
                'status' => 'pending',
                'notes' => json_encode([
                    'provider' => 'sslcommerz',
                    'purpose' => 'course_cart',
                    'course_cart_order_id' => $orderId,
                    'coupon_reservation_key' => $coupon['reservation_key'] ?? null,
                ], JSON_THROW_ON_ERROR),
            ]);
            DB::table('course_cart_orders')->where('id', $orderId)->update([
                'payment_transaction_id' => $transaction->id,
                'updated_at' => now(),
            ]);

            return ['order_id' => $orderId, 'transaction' => $transaction, 'coupon_key' => $coupon['reservation_key'] ?? null, 'free' => false];
        }, 3);

        if ($created['free']) {
            return redirect()->route('course-commerce.cart')->with('status', 'Your coupon covered the full cart. All selected courses are now active.');
        }

        try {
            $checkoutUrl = app(StorefrontPaymentGatewayService::class)->initiateSslcommerz(
                $created['transaction'],
                (object) ['title' => 'Course Cart Order #' . $created['order_id']],
                $student,
                trim($validated['payer_contact']),
                [
                    'purpose' => 'course_cart',
                    'course_cart_order_id' => $created['order_id'],
                    'coupon_reservation_key' => $created['coupon_key'],
                ]
            );

            return redirect()->away($checkoutUrl);
        } catch (\Throwable $exception) {
            report($exception);
            $ambiguous = $exception instanceof ConnectionException;
            DB::transaction(function () use ($created, $ambiguous, $couponService): void {
                $transaction = UniversalPaymentTransaction::query()->whereKey($created['transaction']->id)->lockForUpdate()->first();
                $order = DB::table('course_cart_orders')->where('id', $created['order_id'])->lockForUpdate()->first();
                if ($transaction && $transaction->status === 'pending') {
                    $transaction->update([
                        'status' => $ambiguous ? 'risk_review' : 'failed',
                        'notes' => json_encode(['purpose' => 'course_cart', 'course_cart_order_id' => $created['order_id'], 'risk_review' => $ambiguous], JSON_THROW_ON_ERROR),
                    ]);
                }
                if ($order) {
                    DB::table('course_cart_orders')->where('id', $order->id)->update([
                        'status' => $ambiguous ? 'risk_review' : 'payment_failed',
                        'updated_at' => now(),
                    ]);
                    if (!$ambiguous) {
                        DB::table('course_carts')->where('id', $order->cart_id)->update(['status' => 'active', 'updated_at' => now()]);
                    }
                }
                if (!$ambiguous && filled($created['coupon_key'])) {
                    $couponService->release((string) $created['coupon_key']);
                }
            }, 3);

            return back()->withErrors(['checkout' => $ambiguous
                ? 'The payment session outcome is unknown. Contact support before retrying; do not submit another payment.'
                : 'Unable to start secure checkout. Please review your cart and try again.']);
        }
    }

    private function activateOrder(int $orderId, $student, ?int $branchId, CourseCouponService $couponService): void
    {
        $order = DB::table('course_cart_orders')->where('id', $orderId)->lockForUpdate()->first();
        abort_unless($order, 404);
        $items = DB::table('course_cart_order_items')->where('order_id', $orderId)->orderBy('position')->get();
        $invoice = $this->createPaidInvoice($student, $branchId, (float) $order->subtotal_amount, (float) $order->discount_amount, (float) $order->net_amount, (string) $order->currency, 'CART');
        foreach ($items as $item) {
            DB::table('enrollments')->insert([
                'user_id' => $student->id,
                'course_id' => $item->course_id,
                'branch_id' => $branchId,
                'invoice_id' => $invoice->id,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if (filled($order->coupon_reservation_key)) {
            $couponService->redeem((string) $order->coupon_reservation_key);
        }
        DB::table('course_cart_orders')->where('id', $orderId)->update([
            'invoice_id' => $invoice->id,
            'status' => 'paid',
            'paid_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_carts')->where('id', $order->cart_id)->update(['status' => 'completed', 'updated_at' => now()]);
    }

    private function createPaidInvoice($student, ?int $branchId, float $subtotal, float $discount, float $total, string $currency, string $prefix): FeeInvoice
    {
        $studentProfile = $this->plugins->isEnabled('student-management') && Schema::hasTable('students')
            ? DB::table('students')->where('user_id', $student->id)->first()
            : null;

        return FeeInvoice::query()->create([
            'invoice_number' => $prefix . '-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)),
            'student_id' => $studentProfile->id ?? null,
            'payer_name' => $student->name,
            'payer_email' => $student->email,
            'payer_phone' => $student->phone,
            'branch_id' => $branchId,
            'subtotal_amount' => round($subtotal, 2),
            'discount_amount' => round($discount, 2),
            'net_amount' => round($total, 2),
            'paid_amount' => round($total, 2),
            'currency' => strtoupper($currency),
            'status' => 'paid',
            'due_date' => now()->toDateString(),
        ]);
    }

    private function assertCheckoutReady(): void
    {
        abort_unless($this->plugins->isEnabled('universal-payment')
            && $this->plugins->isEnabled('gateway-sslcommerz')
            && $this->plugins->isEnabled('fees-management')
            && Schema::hasTable('universal_payment_transactions')
            && Schema::hasTable('fee_invoices')
            && Schema::hasTable('enrollments'), 503,
            'Enable Universal Payment, SSLCOMMERZ Gateway and Fees Management before purchasing courses online.');
    }

    private function student(Request $request)
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403, 'Course commerce is available to student accounts only.');
        abort_unless($this->plugins->isEnabled('course-management') && Schema::hasTable('courses'), 503, 'Course Management is unavailable.');
        return $student;
    }

    private function assertCourseBranch($student, object $course): void
    {
        if ($course->branch_id !== null) {
            abort_unless($student->branch_id !== null && (int) $student->branch_id === (int) $course->branch_id, 403, 'This course is outside your assigned branch.');
        }
    }

    private function assertNoOpenBatch(int $courseId): void
    {
        if (Schema::hasTable('batches') && Schema::hasColumn('batches', 'status')) {
            abort_if(DB::table('batches')->where('course_id', $courseId)->where('status', 'open')->exists(), 409, 'This course has an open batch. Contact admissions to enroll in the correct batch.');
        }
    }
}
