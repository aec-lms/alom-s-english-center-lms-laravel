<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Models\SystemSetting;
use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\CourseManagement\Jobs\SendGiftRecipientEmailJob;
use Plugins\Modules\MarketingManagement\Services\CourseCouponService;
use Plugins\Modules\ReferralIncome\Services\ReferralIncomeService;
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
        $walletBalance = Schema::hasTable('course_gift_wallets')
            ? (float) DB::table('course_gift_wallets')->where('user_id', $student->id)->where('currency', $currency)->value('balance')
            : 0.0;

        return view('course-management::commerce.cart', compact('cart', 'items', 'subtotal', 'currency', 'walletBalance'));
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
            ->whereIn('status', ['pending', 'active', 'completed'])->exists(), 409, 'You already have a pending or active enrollment in this course.');

        DB::transaction(function () use ($student, $course): void {
            DB::table('users')->where('id', $student->id)->lockForUpdate()->first();
            $this->assertNoUnresolvedOrder((int) $student->id);
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

    public function giftForm(Request $request, int $courseId): View
    {
        $student = $this->student($request);
        $course = DB::table('courses')->where('id', $courseId)->where('status', 'published')->first();
        abort_unless($course, 404, 'This course is not available for gifting.');
        $this->assertCourseBranch($student, $course);
        $this->assertNoOpenBatch($courseId);

        return view('course-management::commerce.gift', compact('course'));
    }

    public function gift(Request $request, int $courseId): RedirectResponse
    {
        $student = $this->student($request);
        $validated = $request->validate([
            'recipient_email' => ['required', 'email:rfc', 'max:190'],
            'recipient_name' => ['nullable', 'string', 'max:160'],
            'message' => ['nullable', 'string', 'max:1000'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'payer_contact' => ['required', 'string', 'min:7', 'max:40'],
        ]);
        if (filled($validated['coupon_code'] ?? null)) {
            $this->assertCouponCheckoutReady();
        }
        $this->assertCheckoutReady();
        abort_unless(Schema::hasTable('course_gifts') && Schema::hasColumn('course_gifts', 'payment_transaction_id')
            && Schema::hasColumn('course_gifts', 'redeem_token_encrypted'), 503, 'Gift course storage is not ready.');

        $course = DB::table('courses')->where('id', $courseId)->where('status', 'published')->first();
        abort_unless($course, 404, 'This course is not available for gifting.');
        abort_if((float) $course->price <= 0, 422, 'Free courses do not need a paid gift checkout.');
        $this->assertCourseBranch($student, $course);
        $this->assertNoOpenBatch($courseId);
        abort_if(strtolower(trim($validated['recipient_email'])) === strtolower(trim((string) $student->email)), 422,
            'You cannot gift a course to your own account.');

        $couponService = app(CourseCouponService::class);
        $created = DB::transaction(function () use ($student, $course, $validated, $couponService): array {
            // If the recipient already has an account, do not sell a branch-
            // restricted gift that the recipient would be unable to redeem.
            // Settlement repeats this check in case the account's branch changes.
            $recipient = User::query()
                ->whereRaw('LOWER(email) = ?', [strtolower(trim($validated['recipient_email']))])
                ->lockForUpdate()
                ->first();
            if ($recipient) {
                $this->assertCourseBranch($recipient, $course);
            }

            $coupon = filled($validated['coupon_code'] ?? null)
                ? $couponService->reserve((string) $validated['coupon_code'], (int) $student->id, [(int) $course->id], (float) $course->price, true)
                : null;
            $discount = round((float) ($coupon['discount'] ?? 0), 2);
            $payable = round(max(0, (float) $course->price - $discount), 2);
            abort_if($payable <= 0, 422, 'A gift coupon cannot reduce the payment to zero. Please use a different coupon.');
            $token = Str::random(64);
            $giftId = DB::table('course_gifts')->insertGetId([
                'sender_user_id' => $student->id,
                'recipient_user_id' => $recipient?->id,
                'recipient_email' => strtolower(trim($validated['recipient_email'])),
                'recipient_name' => $validated['recipient_name'] ?? null,
                'message' => $validated['message'] ?? null,
                'course_id' => $course->id,
                'redeem_token_hash' => hash('sha256', $token),
                'redeem_token_encrypted' => encrypt($token),
                'status' => 'awaiting_payment',
                'currency' => strtoupper((string) config('lms.default_currency', 'BDT')),
                'amount' => $payable,
                'subtotal_amount' => round((float) $course->price, 2),
                'discount_amount' => $discount,
                'coupon_id' => $coupon['coupon_id'] ?? null,
                'coupon_code' => $coupon['code'] ?? null,
                'coupon_reservation_key' => $coupon['reservation_key'] ?? null,
                'expires_at' => now()->addDays(90),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $transaction = UniversalPaymentTransaction::query()->create([
                'reference' => 'GIFT-' . strtoupper(Str::random(10)),
                'payer_name' => $student->name,
                'payer_contact' => trim($validated['payer_contact']),
                'item_title' => 'Gift Course: ' . $course->title,
                'gateway' => 'sslcommerz',
                'currency' => strtoupper((string) config('lms.default_currency', 'BDT')),
                'amount' => $payable,
                'external_trx_id' => null,
                'user_id' => $student->id,
                'branch_id' => $course->branch_id ?? $this->assignedBranchId($student),
                'status' => 'pending',
                'notes' => json_encode(['purpose' => 'course_gift', 'course_gift_id' => $giftId], JSON_THROW_ON_ERROR),
            ]);
            DB::table('course_gifts')->where('id', $giftId)->update([
                'payment_transaction_id' => $transaction->id,
                'updated_at' => now(),
            ]);

            return ['gift_id' => $giftId, 'transaction' => $transaction, 'token' => $token, 'coupon_key' => $coupon['reservation_key'] ?? null];
        }, 3);

        try {
            $url = app(StorefrontPaymentGatewayService::class)->initiateSslcommerz(
                $created['transaction'],
                (object) ['title' => 'Gift Course #' . $created['gift_id']],
                $student,
                trim($validated['payer_contact']),
                ['purpose' => 'course_gift', 'course_gift_id' => $created['gift_id'], 'coupon_reservation_key' => $created['coupon_key']]
            );

            return redirect()->away($url);
        } catch (\Throwable $exception) {
            report($exception);
            $ambiguous = $exception instanceof ConnectionException;
            DB::transaction(function () use ($created, $ambiguous, $couponService): void {
                $transaction = UniversalPaymentTransaction::query()->whereKey($created['transaction']->id)->lockForUpdate()->first();
                if ($transaction && $transaction->status === 'pending') {
                    $transaction->update(['status' => $ambiguous ? 'risk_review' : 'failed']);
                }
                if (!$transaction || !in_array($transaction->status, ['verified', 'risk_review'], true)) {
                    DB::table('course_gifts')->where('id', $created['gift_id'])->where('status', 'awaiting_payment')
                        ->update(['status' => $ambiguous ? 'risk_review' : 'payment_failed', 'updated_at' => now()]);
                }
                if (!$ambiguous && (!$transaction || !in_array($transaction->status, ['verified', 'risk_review'], true))
                    && filled($created['coupon_key'])) {
                    $couponService->release((string) $created['coupon_key']);
                }
            }, 3);

            return back()->withErrors(['gift' => $ambiguous
                ? 'The payment session outcome is unknown. Contact support before retrying this gift.'
                : 'Secure gift checkout could not be started. Please try again.']);
        }
    }

    public function giftRedeemForm(Request $request, string $token): View
    {
        $student = $this->student($request);
        abort_unless(preg_match('/^[A-Za-z0-9]{40,80}$/', $token) === 1, 404);
        $gift = DB::table('course_gifts')
            ->join('courses', 'courses.id', '=', 'course_gifts.course_id')
            ->where('course_gifts.redeem_token_hash', hash('sha256', $token))
            ->where('course_gifts.status', 'paid')
            ->first(['course_gifts.*', 'courses.title as course_title']);
        abort_unless($gift, 404, 'This gift is not available for redemption.');
        abort_if($gift->expires_at && now()->greaterThan($gift->expires_at), 410, 'This gift has expired.');
        abort_unless(hash_equals(strtolower((string) $gift->recipient_email), strtolower((string) $student->email)),
            403, 'Sign in with the email address that received this gift.');
        abort_if((int) $gift->sender_user_id === (int) $student->id, 403, 'The sender cannot redeem their own gift.');

        return view('course-management::commerce.gift-redeem', compact('gift', 'token'));
    }

    public function redeemGift(Request $request, string $token): RedirectResponse
    {
        $student = $this->student($request);
        abort_unless(preg_match('/^[A-Za-z0-9]{40,80}$/', $token) === 1, 404);
        $gift = DB::table('course_gifts')->where('redeem_token_hash', hash('sha256', $token))->first();
        abort_unless($gift, 404, 'This gift is not available for redemption.');
        abort_unless(hash_equals(strtolower((string) $gift->recipient_email), strtolower((string) $student->email)),
            403, 'Sign in with the email address that received this gift.');
        abort_unless($gift->status === 'paid', 409, 'This gift has already been redeemed or is no longer available.');
        abort_if($gift->expires_at && now()->greaterThan($gift->expires_at), 410, 'This gift has expired.');
        abort_if($gift->sender_user_id === $student->id, 403, 'The sender cannot redeem their own gift.');

        return DB::transaction(function () use ($gift, $student): RedirectResponse {
            // Serialize all gift claims for this account. This prevents duplicate
            // enrollments when two distinct gifts for the same course are claimed
            // concurrently and keeps the recipient email stable during redemption.
            $lockedStudent = DB::table('users')->where('id', $student->id)->lockForUpdate()->first();
            abort_unless($lockedStudent, 404, 'The recipient account is no longer available.');
            $recipient = \App\Models\User::query()->findOrFail($student->id);
            // Lock the authoritative student-profile branch assignment as well
            // as the user row before evaluating course access.
            if ($this->plugins->isEnabled('student-management') && Schema::hasTable('students')) {
                DB::table('students')->where('user_id', $recipient->id)->lockForUpdate()->first();
            }
            $locked = DB::table('course_gifts')->where('id', $gift->id)->lockForUpdate()->first();
            abort_unless($locked && $locked->status === 'paid', 409, 'This gift has already been redeemed or is no longer available.');
            // Re-check authorization and expiry after acquiring the row lock to
            // prevent a request queued before expiry from redeeming it afterward.
            abort_if($locked->expires_at && now()->greaterThan($locked->expires_at), 410, 'This gift has expired.');
            abort_unless(hash_equals(strtolower((string) $locked->recipient_email), strtolower((string) $lockedStudent->email)),
                403, 'Sign in with the email address that received this gift.');
            abort_if((int) $locked->sender_user_id === (int) $student->id, 403, 'The sender cannot redeem their own gift.');
            $this->assertGiftInvoiceFullyPaid($locked->invoice_id ?? null, $locked->payment_transaction_id ?? null, (float) $locked->amount, (string) $locked->currency, $locked->unified_payment_transaction_id ?? null);
            $course = DB::table('courses')->where('id', $locked->course_id)->where('status', 'published')->first();
            abort_unless($course, 409, 'The gifted course is currently unavailable. Please contact support.');
            $this->assertCourseBranch($recipient, $course);
            abort_if(DB::table('enrollments')->where('user_id', $recipient->id)->where('course_id', $course->id)
                ->whereIn('status', ['pending', 'active', 'completed'])->exists(), 409, 'You already have access to this course.');

            DB::table('enrollments')->insert([
                'user_id' => $recipient->id,
                'course_id' => $course->id,
                'branch_id' => $course->branch_id ?? $this->assignedBranchId($recipient),
                'invoice_id' => $locked->invoice_id,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('course_gifts')->where('id', $locked->id)->update([
                'recipient_user_id' => $recipient->id,
                'status' => 'redeemed',
                'redeemed_at' => now(),
                'updated_at' => now(),
            ]);

            return redirect()->route('plugins.enrollments.my')->with('status', 'Gift redeemed. The course is now available in your learning area.');
        }, 3);
    }

    public function sentGifts(Request $request): View
    {
        $sender = $this->student($request);
        foreach (['course_gifts', 'course_gift_vouchers'] as $table) {
            abort_unless(Schema::hasTable($table)
                && Schema::hasColumn($table, 'notification_delivery_status')
                && Schema::hasColumn($table, 'notification_sent_at'), 503,
                'Gift delivery tracking is not installed.');
        }

        $courseGifts = DB::table('course_gifts')
            ->leftJoin('courses', 'courses.id', '=', 'course_gifts.course_id')
            ->where('course_gifts.sender_user_id', $sender->id)
            ->orderByDesc('course_gifts.id')
            ->limit(100)
            ->get(['course_gifts.*', 'courses.title as course_title'])
            ->map(function ($gift): array {
                $redeemUrl = null;
                if ($gift->status === 'paid' && (!$gift->expires_at || now()->lessThanOrEqualTo($gift->expires_at))) {
                    try {
                        $token = decrypt((string) $gift->redeem_token_encrypted);
                        if (is_string($token) && preg_match('/^[A-Za-z0-9]{40,80}$/', $token) === 1
                            && hash_equals((string) $gift->redeem_token_hash, hash('sha256', $token))) {
                            $redeemUrl = route('course-commerce.gifts.redeem-form', ['token' => $token]);
                        }
                    } catch (\Throwable) {
                        $redeemUrl = null;
                    }
                }

                return [
                    'id' => (int) $gift->id,
                    'type' => 'Course gift',
                    'title' => (string) ($gift->course_title ?? 'Course unavailable'),
                    'recipient_name' => $gift->recipient_name,
                    'recipient_email' => $gift->recipient_email,
                    'amount' => (float) $gift->amount,
                    'currency' => $gift->currency,
                    'status' => $gift->status,
                    'delivery_status' => $gift->notification_delivery_status,
                    'delivery_error' => $gift->notification_last_error ?? null,
                    'sent_at' => $gift->notification_sent_at,
                    'expires_at' => $gift->expires_at,
                    'redeem_url' => $redeemUrl,
                    'resend_url' => route('course-commerce.gifts.resend', ['gift' => $gift->id]),
                ];
            });

        $vouchers = DB::table('course_gift_vouchers')
            ->where('sender_user_id', $sender->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(function ($gift): array {
                $redeemUrl = null;
                if ($gift->status === 'paid' && (!$gift->expires_at || now()->lessThanOrEqualTo($gift->expires_at))) {
                    try {
                        $token = decrypt((string) $gift->redeem_token_encrypted);
                        if (is_string($token) && preg_match('/^[A-Za-z0-9]{40,80}$/', $token) === 1
                            && hash_equals((string) $gift->redeem_token_hash, hash('sha256', $token))) {
                            $redeemUrl = route('course-commerce.vouchers.redeem-form', ['token' => $token]);
                        }
                    } catch (\Throwable) {
                        $redeemUrl = null;
                    }
                }

                return [
                    'id' => (int) $gift->id,
                    'type' => 'Gift voucher',
                    'title' => 'Gift voucher',
                    'recipient_name' => $gift->recipient_name,
                    'recipient_email' => $gift->recipient_email,
                    'amount' => (float) $gift->amount,
                    'currency' => $gift->currency,
                    'status' => $gift->status,
                    'delivery_status' => $gift->notification_delivery_status,
                    'delivery_error' => $gift->notification_last_error ?? null,
                    'sent_at' => $gift->notification_sent_at,
                    'expires_at' => $gift->expires_at,
                    'redeem_url' => $redeemUrl,
                    'resend_url' => route('course-commerce.vouchers.resend', ['voucher' => $gift->id]),
                ];
            });

        $gifts = $courseGifts->concat($vouchers)->sortByDesc('id')->values();

        $emailDeliveryEnabled = $this->plugins->isEnabled('email-notifications');

        return view('course-management::commerce.gifts-sent', compact('gifts', 'emailDeliveryEnabled'));
    }

    public function resendCourseGiftEmail(Request $request, int $giftId): RedirectResponse
    {
        return $this->resendGiftEmail($request, 'gift_course', $giftId);
    }

    public function resendGiftVoucherEmail(Request $request, int $voucherId): RedirectResponse
    {
        return $this->resendGiftEmail($request, 'gift_voucher', $voucherId);
    }

    private function resendGiftEmail(Request $request, string $giftType, int $giftId): RedirectResponse
    {
        $sender = $this->student($request);
        abort_unless($this->plugins->isEnabled('email-notifications'), 503,
            'Enable the Email Notifications plugin before retrying gift delivery.');
        $table = $giftType === 'gift_course' ? 'course_gifts' : 'course_gift_vouchers';
        abort_unless(Schema::hasTable($table)
            && Schema::hasColumn($table, 'notification_delivery_status')
            && Schema::hasColumn($table, 'notification_sent_at'), 503,
            'Gift delivery tracking is not installed.');

        DB::transaction(function () use ($sender, $table, $giftType, $giftId): void {
            $gift = DB::table($table)->where('id', $giftId)->lockForUpdate()->first();
            abort_unless($gift && (int) $gift->sender_user_id === (int) $sender->id, 404);
            abort_unless($gift->status === 'paid', 409, 'Only paid gifts can be delivered.');
            abort_if($gift->notification_sent_at !== null, 409, 'This gift has already been emailed.');
            abort_if($gift->expires_at && now()->greaterThan($gift->expires_at), 410, 'This gift has expired.');
            abort_unless($gift->notification_delivery_status === 'failed', 409,
                'Only a failed gift email can be manually retried.');

            DB::table($table)->where('id', $giftId)->update([
                'notification_delivery_status' => 'pending',
                'notification_last_error' => null,
                'updated_at' => now(),
            ]);

            SendGiftRecipientEmailJob::dispatch($giftType, $giftId)->afterCommit();
        });

        return back()->with('status', 'Gift email retry has been queued. Delivery status will update after the mail server accepts it.');
    }

    public function voucherForm(Request $request): View
    {
        $this->student($request);
        $voucherSettings = $this->giftVoucherSettings();
        abort_unless($voucherSettings['enabled'], 403, 'Gift voucher purchases are temporarily disabled.');

        return view('course-management::commerce.voucher', compact('voucherSettings'));
    }

    public function purchaseVoucher(Request $request): RedirectResponse
    {
        $student = $this->student($request);
        $voucherSettings = $this->giftVoucherSettings();
        abort_unless($voucherSettings['enabled'], 403, 'Gift voucher purchases are temporarily disabled.');
        $validated = $request->validate([
            'recipient_email' => ['required', 'email:rfc', 'max:190'],
            'recipient_name' => ['nullable', 'string', 'max:160'],
            'message' => [$voucherSettings['allow_personal_message'] ? 'nullable' : 'prohibited', 'string', 'max:1000'],
            'amount' => ['required', 'numeric', 'min:' . $voucherSettings['minimum_amount'], 'max:' . $voucherSettings['maximum_amount']],
            'payer_contact' => ['required', 'string', 'min:7', 'max:40'],
        ]);
        $this->assertCheckoutReady();
        abort_unless(Schema::hasTable('course_gift_vouchers') && Schema::hasTable('course_gift_wallets')
            && Schema::hasTable('course_gift_wallet_transactions'), 503, 'Gift voucher storage is not ready.');
        abort_if(strtolower(trim($validated['recipient_email'])) === strtolower(trim((string) $student->email)), 422,
            'You cannot send a gift voucher to your own account.');

        $created = DB::transaction(function () use ($student, $validated, $voucherSettings): array {
            $token = Str::random(64);
            $amount = round((float) $validated['amount'], 2);
            $voucherId = DB::table('course_gift_vouchers')->insertGetId([
                'sender_user_id' => $student->id,
                'recipient_email' => strtolower(trim($validated['recipient_email'])),
                'recipient_name' => $validated['recipient_name'] ?? null,
                'message' => $validated['message'] ?? null,
                'redeem_token_hash' => hash('sha256', $token),
                'redeem_token_encrypted' => encrypt($token),
                'currency' => strtoupper((string) config('lms.default_currency', 'BDT')),
                'amount' => $amount,
                'remaining_amount' => $amount,
                'status' => 'awaiting_payment',
                'expires_at' => now()->addDays($voucherSettings['validity_days']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $transaction = UniversalPaymentTransaction::query()->create([
                'reference' => 'VOUCHER-' . strtoupper(Str::random(10)),
                'payer_name' => $student->name,
                'payer_contact' => trim($validated['payer_contact']),
                'item_title' => 'Gift Voucher #' . $voucherId,
                'gateway' => 'sslcommerz',
                'currency' => strtoupper((string) config('lms.default_currency', 'BDT')),
                'amount' => $amount,
                'external_trx_id' => null,
                'user_id' => $student->id,
                'branch_id' => $this->assignedBranchId($student),
                'status' => 'pending',
                'notes' => json_encode(['purpose' => 'course_gift_voucher', 'course_gift_voucher_id' => $voucherId], JSON_THROW_ON_ERROR),
            ]);
            DB::table('course_gift_vouchers')->where('id', $voucherId)->update([
                'payment_transaction_id' => $transaction->id,
                'updated_at' => now(),
            ]);

            return ['voucher_id' => $voucherId, 'transaction' => $transaction];
        }, 3);

        try {
            $url = app(StorefrontPaymentGatewayService::class)->initiateSslcommerz(
                $created['transaction'],
                (object) ['title' => 'Gift Voucher #' . $created['voucher_id']],
                $student,
                trim($validated['payer_contact']),
                ['purpose' => 'course_gift_voucher', 'course_gift_voucher_id' => $created['voucher_id']]
            );

            return redirect()->away($url);
        } catch (\Throwable $exception) {
            report($exception);
            $ambiguous = $exception instanceof ConnectionException;
            DB::transaction(function () use ($created, $ambiguous): void {
                $transaction = UniversalPaymentTransaction::query()->whereKey($created['transaction']->id)->lockForUpdate()->first();
                if ($transaction && $transaction->status === 'pending') {
                    $transaction->update(['status' => $ambiguous ? 'risk_review' : 'failed']);
                }
                if (!$transaction || !in_array($transaction->status, ['verified', 'risk_review'], true)) {
                    DB::table('course_gift_vouchers')->where('id', $created['voucher_id'])->where('status', 'awaiting_payment')
                        ->update(['status' => $ambiguous ? 'risk_review' : 'payment_failed', 'updated_at' => now()]);
                }
            }, 3);

            return back()->withErrors(['voucher' => $ambiguous
                ? 'The payment session outcome is unknown. Contact support before retrying this voucher.'
                : 'Secure voucher checkout could not be started. Please try again.']);
        }
    }

    public function voucherRedeemForm(Request $request, string $token): View
    {
        $student = $this->student($request);
        abort_unless(preg_match('/^[A-Za-z0-9]{40,80}$/', $token) === 1, 404);
        $voucher = DB::table('course_gift_vouchers')->where('redeem_token_hash', hash('sha256', $token))
            ->where('status', 'paid')->first();
        abort_unless($voucher, 404, 'This gift voucher is not available.');
        abort_if($voucher->expires_at && now()->greaterThan($voucher->expires_at), 410, 'This gift voucher has expired.');
        abort_if((int) $voucher->sender_user_id === (int) $student->id, 403, 'The sender cannot redeem their own voucher.');
        abort_unless(hash_equals(strtolower((string) $voucher->recipient_email), strtolower((string) $student->email)),
            403, 'Sign in with the email address that received this voucher.');
        return view('course-management::commerce.voucher-redeem', compact('voucher', 'token'));
    }

    public function redeemVoucher(Request $request, string $token): RedirectResponse
    {
        $student = $this->student($request);
        abort_unless(preg_match('/^[A-Za-z0-9]{40,80}$/', $token) === 1, 404);

        DB::transaction(function () use ($student, $token): void {
            // Lock the recipient first so concurrent voucher claims cannot race
            // wallet creation or credit against a stale account email.
            $lockedStudent = DB::table('users')->where('id', $student->id)->lockForUpdate()->first();
            abort_unless($lockedStudent, 404, 'The recipient account is no longer available.');
            $voucher = DB::table('course_gift_vouchers')->where('redeem_token_hash', hash('sha256', $token))
                ->lockForUpdate()->first();
            abort_unless($voucher && $voucher->status === 'paid', 409, 'This gift voucher has already been claimed or is unavailable.');
            abort_if((int) $voucher->sender_user_id === (int) $student->id, 403, 'The sender cannot redeem their own voucher.');
            $remainingAmount = round((float) $voucher->remaining_amount, 2);
            $voucherAmount = round((float) $voucher->amount, 2);
            abort_if($remainingAmount <= 0 || $remainingAmount > $voucherAmount + 0.01, 409, 'Gift voucher remaining balance is invalid; contact support.');
            $this->assertGiftInvoiceFullyPaid($voucher->invoice_id ?? null, $voucher->payment_transaction_id ?? null, (float) $voucher->amount, (string) $voucher->currency, $voucher->unified_payment_transaction_id ?? null);
            abort_if($voucher->expires_at && now()->greaterThan($voucher->expires_at), 410, 'This gift voucher has expired.');
            abort_unless(hash_equals(strtolower((string) $voucher->recipient_email), strtolower((string) $lockedStudent->email)),
                403, 'Sign in with the email address that received this voucher.');

            $currency = strtoupper((string) $voucher->currency);
            $wallet = DB::table('course_gift_wallets')->where('user_id', $student->id)->where('currency', $currency)->lockForUpdate()->first();
            if (!$wallet) {
                $walletId = DB::table('course_gift_wallets')->insertGetId([
                    'user_id' => $student->id, 'currency' => $currency, 'balance' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $wallet = DB::table('course_gift_wallets')->where('id', $walletId)->lockForUpdate()->first();
            }

            $reference = 'gift-voucher-credit:' . $voucher->id;
            abort_if(DB::table('course_gift_wallet_transactions')->where('reference', $reference)->exists(), 409,
                'This voucher has already been credited to a wallet.');
            DB::table('course_gift_wallets')->where('id', $wallet->id)->update([
                'balance' => round((float) $wallet->balance + $remainingAmount, 2),
                'updated_at' => now(),
            ]);
            DB::table('course_gift_wallet_transactions')->insert([
                'wallet_id' => $wallet->id,
                'user_id' => $student->id,
                'voucher_id' => $voucher->id,
                'type' => 'credit',
                'status' => 'completed',
                'reference' => $reference,
                'amount' => $remainingAmount,
                'metadata' => json_encode(['source' => 'gift_voucher_claim'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('course_gift_voucher_redemptions')->insert([
                'voucher_id' => $voucher->id,
                'user_id' => $student->id,
                'course_id' => null,
                'invoice_id' => null,
                'amount' => $remainingAmount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('course_gift_vouchers')->where('id', $voucher->id)->update([
                'recipient_user_id' => $student->id,
                'remaining_amount' => 0,
                'status' => 'redeemed',
                'redeemed_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);

        return redirect()->route('course-commerce.cart')->with('status', 'Gift voucher claimed. Its value is now available in your course wallet and will be applied automatically at checkout.');
    }

    public function checkout(Request $request): RedirectResponse
    {
        $student = $this->student($request);
        $validated = $request->validate([
            'payer_contact' => ['required', 'string', 'min:7', 'max:40'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);
        if (filled($validated['coupon_code'] ?? null)) {
            $this->assertCouponCheckoutReady();
        }
        $this->assertCheckoutStorageReady();

        $couponService = new CourseCouponService();
        $created = DB::transaction(function () use ($student, $validated, $couponService): array {
            DB::table('users')->where('id', $student->id)->lockForUpdate()->first();
            $this->assertNoUnresolvedOrder((int) $student->id);
            $cart = DB::table('course_carts')->where('user_id', $student->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($cart, 422, 'Your course cart is empty.');

            $cartItems = DB::table('course_cart_items')->where('cart_id', $cart->id)->orderBy('id')->lockForUpdate()->get();
            abort_if($cartItems->isEmpty(), 422, 'Your course cart is empty.');
            $courseIds = $cartItems->pluck('course_id')->map(fn ($id): int => (int) $id)->all();
            $courses = DB::table('courses')->whereIn('id', $courseIds)->where('status', 'published')->get()->keyBy('id');
            abort_unless($courses->count() === count($courseIds), 409, 'A course in your cart is no longer available. Review your cart and try again.');

            $branchIds = $courses->pluck('branch_id')->filter()->unique();
            abort_if($branchIds->count() > 1, 422, 'Courses from different branches cannot be purchased in one cart.');
            $assignedBranchId = $this->assignedBranchId($student);
            $branchId = $branchIds->isNotEmpty() ? (int) $branchIds->first() : $assignedBranchId;
            if ($branchId !== null) {
                abort_unless($assignedBranchId !== null && $branchId === $assignedBranchId, 403, 'Your cart contains a course outside your assigned branch.');
            }

            foreach ($cartItems as $cartItem) {
                $course = $courses->get($cartItem->course_id);
                abort_if(abs((float) $course->price - (float) $cartItem->unit_price) > 0.01, 409, 'A course price changed. Review your cart before paying.');
                $this->assertNoOpenBatch((int) $course->id);
                abort_if(DB::table('enrollments')->where('user_id', $student->id)->where('course_id', $course->id)
                    ->whereIn('status', ['pending', 'active', 'completed'])->exists(), 409, 'You already have a pending or active enrollment in one of the selected courses.');
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
            $walletCredit = 0.0;
            if (Schema::hasTable('course_gift_wallets')) {
                $wallet = DB::table('course_gift_wallets')->where('user_id', $student->id)
                    ->where('currency', $currency)->lockForUpdate()->first();
                if ($wallet && (float) $wallet->balance > 0) {
                    $walletCredit = round(min($total, (float) $wallet->balance), 2);
                }
            }
            $gatewayAmount = round(max(0, $total - $walletCredit), 2);
            if ($gatewayAmount > 0) {
                $this->assertGatewayCheckoutReady();
            }

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
                'wallet_credit_amount' => $walletCredit,
                'currency' => $currency,
                'status' => $gatewayAmount > 0 ? 'awaiting_payment' : 'processing',
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

            if ($walletCredit > 0) {
                $wallet = DB::table('course_gift_wallets')->where('user_id', $student->id)->where('currency', $currency)->lockForUpdate()->first();
                abort_unless($wallet && (float) $wallet->balance >= $walletCredit, 409, 'Your gift wallet balance changed. Please retry checkout.');
                $walletReservation = 'course-cart-wallet:' . $orderId;
                DB::table('course_gift_wallets')->where('id', $wallet->id)->update([
                    'balance' => round((float) $wallet->balance - $walletCredit, 2),
                    'updated_at' => now(),
                ]);
                DB::table('course_gift_wallet_transactions')->insert([
                    'wallet_id' => $wallet->id,
                    'user_id' => $student->id,
                    'type' => 'debit',
                    'status' => 'reserved',
                    'reference' => $walletReservation,
                    'amount' => $walletCredit,
                    'metadata' => json_encode(['order_id' => $orderId], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('course_cart_orders')->where('id', $orderId)->update([
                    'wallet_reservation_key' => $walletReservation,
                    'updated_at' => now(),
                ]);
            }

            DB::table('course_carts')->where('id', $cart->id)->update(['status' => 'checkout_pending', 'updated_at' => now()]);

            if ($gatewayAmount <= 0) {
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
                'amount' => $gatewayAmount,
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
            return redirect()->route('course-commerce.cart')->with('status', 'Your course wallet or coupon covered the full cart. All selected courses are now active.');
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
                    $existingNotes = json_decode((string) $transaction->notes, true);
                    $existingNotes = is_array($existingNotes) ? $existingNotes : [];
                    $transaction->update([
                        'status' => $ambiguous ? 'risk_review' : 'failed',
                        'notes' => json_encode(array_merge($existingNotes, ['purpose' => 'course_cart', 'course_cart_order_id' => $created['order_id'], 'risk_review' => $ambiguous]), JSON_THROW_ON_ERROR),
                    ]);
                }
                $alreadySettled = $transaction && $transaction->status === 'verified';
                $needsReview = $ambiguous || ($transaction && $transaction->status === 'risk_review');
                if ($order && !$alreadySettled) {
                    DB::table('course_cart_orders')->where('id', $order->id)->update([
                        'status' => $needsReview ? 'risk_review' : 'payment_failed',
                        'updated_at' => now(),
                    ]);
                    if (!$needsReview) {
                        DB::table('course_carts')->where('id', $order->cart_id)->update(['status' => 'active', 'updated_at' => now()]);
                    }
                }
                if (!$alreadySettled && !$needsReview && filled($created['coupon_key'])) {
                    $couponService->release((string) $created['coupon_key']);
                }
                if (!$alreadySettled && !$needsReview && $order && filled($order->wallet_reservation_key)) {
                    $walletEntry = DB::table('course_gift_wallet_transactions')->where('reference', $order->wallet_reservation_key)
                        ->where('status', 'reserved')->lockForUpdate()->first();
                    if ($walletEntry) {
                        $wallet = DB::table('course_gift_wallets')->where('id', $walletEntry->wallet_id)->lockForUpdate()->first();
                        if ($wallet) {
                            DB::table('course_gift_wallets')->where('id', $wallet->id)->update([
                                'balance' => round((float) $wallet->balance + (float) $walletEntry->amount, 2),
                                'updated_at' => now(),
                            ]);
                        }
                        DB::table('course_gift_wallet_transactions')->where('id', $walletEntry->id)
                            ->update(['status' => 'reversed', 'updated_at' => now()]);
                    }
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
        if (filled($order->wallet_reservation_key)) {
            DB::table('course_gift_wallet_transactions')->where('reference', $order->wallet_reservation_key)
                ->where('status', 'reserved')->update(['status' => 'completed', 'updated_at' => now()]);
        }

        if ($this->plugins->isEnabled('referral-income')) {
            $buyer = User::query()->find((int) $student->id);
            if ($buyer) {
                app(ReferralIncomeService::class)->recordSettledPurchase(
                    $buyer,
                    'course_cart_order',
                    (string) $orderId,
                    (float) $order->net_amount,
                    (string) $order->currency
                );
            }
        }
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

    /**
     * A paid gift or voucher is redeemable only while its source invoice remains
     * fully paid. This fails closed after an invoice refund/adjustment and is
     * intentionally checked under the caller's redemption transaction lock.
     * Legacy records without an invoice remain supported for backward compatibility.
     */
    private function assertGiftInvoiceFullyPaid($invoiceId, $paymentTransactionId = null, ?float $expectedAmount = null, ?string $expectedCurrency = null, $unifiedPaymentTransactionId = null): void
    {
        // New gift/voucher purchases must be backed by the exact verified
        // universal-payment transaction that created them. Keep the nullable
        // transaction fallback only for historical records created before this
        // linkage existed.
        abort_unless($paymentTransactionId === null || $unifiedPaymentTransactionId === null, 409,
            'Gift payment linkage is ambiguous and requires manual reconciliation.');

        if ($unifiedPaymentTransactionId !== null) {
            abort_unless(Schema::hasTable('payment_transactions'), 503, 'Unified payment transaction storage is unavailable.');
            $payment = DB::table('payment_transactions')
                ->where('id', $unifiedPaymentTransactionId)
                ->lockForUpdate()
                ->first();

            abort_unless($payment !== null
                && (string) $payment->status === 'settled'
                && (int) $payment->invoice_id === (int) $invoiceId
                && ($expectedCurrency === null || strtoupper((string) $payment->currency) === strtoupper($expectedCurrency)),
                409,
                'This gift or voucher unified payment has not been settled or no longer matches its purchase.'
            );
        }

        if ($paymentTransactionId !== null) {
            abort_unless(Schema::hasTable('universal_payment_transactions'), 503, 'Gift payment transaction storage is unavailable.');
            $payment = DB::table('universal_payment_transactions')
                ->where('id', $paymentTransactionId)
                ->lockForUpdate()
                ->first();

            abort_unless($payment !== null
                && (string) $payment->status === 'verified'
                && ($expectedAmount === null || abs((float) $payment->amount - $expectedAmount) <= 0.01)
                && ($expectedCurrency === null || strtoupper((string) $payment->currency) === strtoupper($expectedCurrency)),
                409,
                'This gift or voucher payment has not been verified or no longer matches its purchase.'
            );

            abort_unless($invoiceId !== null, 409, 'This gift or voucher has no verified payment invoice and cannot be redeemed.');
        }

        if ($invoiceId === null) {
            return;
        }

        abort_unless(Schema::hasTable('fee_invoices'), 503, 'Gift payment invoice storage is unavailable.');
        $invoice = DB::table('fee_invoices')->where('id', $invoiceId)->lockForUpdate()->first();
        abort_unless($invoice !== null
            && (string) $invoice->status === 'paid'
            && (float) $invoice->paid_amount + 0.01 >= (float) $invoice->net_amount
            && ($expectedAmount === null || abs(round((float) $invoice->net_amount, 2) - round($expectedAmount, 2)) <= 0.01)
            && ($expectedCurrency === null || strtoupper((string) $invoice->currency) === strtoupper($expectedCurrency)),
            409,
            'This gift or voucher invoice is not fully paid or no longer matches the original amount and currency.'
        );
    }

    private function assertCheckoutStorageReady(): void
    {
        abort_unless($this->plugins->isEnabled('fees-management')
            && Schema::hasTable('fee_invoices')
            && Schema::hasTable('enrollments')
            && Schema::hasColumn('enrollments', 'invoice_id')
            && Schema::hasTable('course_cart_orders')
            && Schema::hasTable('course_cart_order_items'), 503,
            'Course checkout storage is not ready. Enable Fees Management and install the course-commerce migrations.');
    }

    private function assertGatewayCheckoutReady(): void
    {
        abort_unless($this->plugins->isEnabled('universal-payment')
            && $this->plugins->isEnabled('gateway-sslcommerz')
            && Schema::hasTable('universal_payment_transactions'), 503,
            'Enable Universal Payment and the SSLCOMMERZ Gateway before accepting an online course payment.');
    }

    private function assertCheckoutReady(): void
    {
        $this->assertCheckoutStorageReady();
        $this->assertGatewayCheckoutReady();
    }

    private function assertCouponCheckoutReady(): void
    {
        abort_unless($this->plugins->isEnabled('marketing-management')
            && Schema::hasTable('promo_coupons')
            && Schema::hasTable('promo_coupon_redemptions'), 503,
            'Enable Marketing & Promo Campaigns before applying a course coupon.');
    }

    private function assertNoUnresolvedOrder(int $userId): void
    {
        if (!Schema::hasTable('course_cart_orders')) {
            return;
        }

        $hasUnresolvedOrder = DB::table('course_cart_orders')
            ->where('user_id', $userId)
            ->whereIn('status', ['awaiting_payment', 'risk_review', 'processing'])
            ->exists();

        abort_if($hasUnresolvedOrder, 409,
            'A previous course order is awaiting payment confirmation or reconciliation. Contact support before starting another checkout.');
    }

    private function giftVoucherSettings(): array
    {
        $stored = SystemSetting::query()->where('key', 'course_commerce.gift_vouchers')->value('value');
        $decoded = is_array($stored) ? $stored : (json_decode((string) $stored, true) ?: []);

        return array_merge([
            'enabled' => true,
            'minimum_amount' => 100,
            'maximum_amount' => 100000,
            'validity_days' => 180,
            'allow_personal_message' => true,
        ], $decoded);
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
            $assignedBranchId = $this->assignedBranchId($student);
            abort_unless($assignedBranchId !== null && $assignedBranchId === (int) $course->branch_id, 403, 'This course is outside your assigned branch.');
        }
    }

    private function assignedBranchId($student): ?int
    {
        if ($this->plugins->isEnabled('student-management') && Schema::hasTable('students')) {
            $profileBranch = DB::table('students')->where('user_id', $student->id)->value('branch_id');
            if ($profileBranch !== null) {
                return (int) $profileBranch;
            }
        }

        return $student->branch_id !== null ? (int) $student->branch_id : null;
    }

    private function assertNoOpenBatch(int $courseId): void
    {
        if (Schema::hasTable('batches') && Schema::hasColumn('batches', 'status') && Schema::hasColumn('batches', 'course_id')) {
            abort_if(DB::table('batches')->where('course_id', $courseId)->where('status', 'open')->exists(), 409, 'This course has an open batch. Contact admissions to enroll in the correct batch.');
        }
    }
}
