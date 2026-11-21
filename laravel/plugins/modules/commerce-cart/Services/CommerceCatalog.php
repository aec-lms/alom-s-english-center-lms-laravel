<?php

declare(strict_types=1);

namespace Plugins\Modules\CommerceCart\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CommerceCatalog
{
    /**
     * Resolve a purchasable item from an existing LMS module or the shared
     * commerce sellables catalogue. Prices are always read server-side.
     *
     * @return array{type:string,id:int,title:string,price:float,currency:string,metadata:array}
     */
    public function resolve(string $type, int $id, ?Authenticatable $user = null): array
    {
        $type = strtolower(trim($type));
        return match ($type) {
            'course' => $this->course($id, $user),
            'ebook' => $this->ebook($id, $user),
            'mock_test', 'exam' => $this->exam($id, $user),
            'subscription', 'live_class', 'other' => $this->sellable($type, $id, $user),
            'gift_course' => $this->giftCourse($id, $user),
            'gift_voucher' => $this->giftVoucher($id, $user),
            default => throw ValidationException::withMessages(['item' => 'This item type is not available in the unified store.']),
        };
    }

    /**
     * Prevent checkout from silently charging a changed catalogue price,
     * currency, or product title after the student added the item.
     */
    public function assertCartSnapshotUnchanged(array $fresh, object $cartItem): void
    {
        if ((float) $fresh['price'] !== (float) $cartItem->unit_price
            || strtoupper((string) $fresh['currency']) !== strtoupper((string) $cartItem->currency)
            || (string) $fresh['title'] !== (string) $cartItem->title_snapshot) {
            throw ValidationException::withMessages([
                'cart' => 'A cart item changed since it was added. Remove it and add it again to review the current price before paying.',
            ]);
        }
    }

    private function course(int $id, ?Authenticatable $user): array
    {
        $this->requireTable('courses');
        $course = DB::table('courses')->where('id', $id)->first();
        if (!$course || ($course->status ?? null) !== 'published') {
            throw ValidationException::withMessages(['item' => 'This course is not currently available for purchase.']);
        }
        $courseBranchId = Schema::hasColumn('courses', 'branch_id') ? ($course->branch_id ?? null) : null;
        $this->assertBranchAccess($user, $courseBranchId);
        if (!Schema::hasTable('enrollments') || !Schema::hasColumn('enrollments', 'invoice_id')) {
            throw new RuntimeException('The course enrollment module must support invoice-linked pending enrollments before courses can be sold through the unified cart.');
        }
        $basePrice = round((float) ($course->price ?? 0), 2);
        $price = $basePrice;
        $metadata = ['course_id' => $id];
        if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('flash-sale') && Schema::hasTable('flash_sales')) {
            $branchId = $courseBranchId !== null ? (int) $courseBranchId : $this->userBranchId($user);
            $quote = app(\Plugins\Modules\FlashSale\Services\FlashSalePricingService::class)->quote($id, $branchId);
            $price = (float) $quote['total'];
            if ($quote['sale']) {
                $metadata['flash_sale'] = [
                    'campaign_id' => (int) $quote['sale']->id,
                    'campaign_title' => (string) $quote['sale']->title,
                    'regular_price' => (float) $quote['subtotal'],
                    'discount_amount' => (float) $quote['discount'],
                ];
            }
        }
        if ($price <= 0) throw ValidationException::withMessages(['item' => 'Free courses should be enrolled in directly, not purchased.']);
        return $this->snapshot('course', $id, (string) $course->title, $price, $metadata);
    }

    private function ebook(int $id, ?Authenticatable $user): array
    {
        $this->requireTable('storefront_items');
        $item = DB::table('storefront_items')->where('id', $id)->where('type', 'ebook')->where('is_active', true)->first();
        if (!$item || (bool) ($item->is_free ?? false) || (float) $item->price <= 0
            || !str_starts_with((string) ($item->download_url ?? ''), 'private:')) {
            throw ValidationException::withMessages(['item' => 'This paid eBook is unavailable or does not have a protected private PDF.']);
        }
        $itemBranchId = Schema::hasColumn('storefront_items', 'branch_id') ? ($item->branch_id ?? null) : null;
        $this->assertBranchAccess($user, $itemBranchId);
        return $this->snapshot('ebook', $id, (string) $item->title, (float) $item->price, ['storefront_item_id' => $id]);
    }

    private function exam(int $id, ?Authenticatable $user): array
    {
        $this->requireTable('exams');
        $exam = DB::table('exams')->where('id', $id)->where('is_published', true)->first();
        if (!$exam) throw ValidationException::withMessages(['item' => 'This mock test is not currently available.']);
        $examBranchId = Schema::hasColumn('exams', 'branch_id') ? ($exam->branch_id ?? null) : null;
        if ($examBranchId === null && isset($exam->course_id) && Schema::hasTable('courses') && Schema::hasColumn('courses', 'branch_id')) {
            $examBranchId = DB::table('courses')->where('id', $exam->course_id)->value('branch_id');
        }
        $this->assertBranchAccess($user, $examBranchId);
        if (!Schema::hasTable('learn_earn_exam_access')) {
            throw new RuntimeException('The Learn & Earn exam access module must be installed before selling paid mock tests.');
        }
        $paidType = Schema::hasColumn('exams', 'learn_earn_type') ? (string) ($exam->learn_earn_type ?? '') : '';
        $price = Schema::hasColumn('exams', 'access_price') ? (float) ($exam->access_price ?? 0) : 0.0;
        if ($paidType !== 'paid' || $price <= 0) {
            throw ValidationException::withMessages(['item' => 'This mock test does not have a paid checkout price configured.']);
        }
        return $this->snapshot('mock_test', $id, (string) $exam->title, $price, ['exam_id' => $id]);
    }

    private function sellable(string $type, int $id, ?Authenticatable $user): array
    {
        $this->requireTable('commerce_sellables');
        $item = DB::table('commerce_sellables')->where('id', $id)->where('type', $type)->where('is_active', true)->first();
        if (!$item || (float) $item->price <= 0) {
            throw ValidationException::withMessages(['item' => 'This paid service is not currently available.']);
        }
        $sellableBranchId = Schema::hasColumn('commerce_sellables', 'branch_id') ? ($item->branch_id ?? null) : null;
        $this->assertBranchAccess($user, $sellableBranchId);
        if ($type === 'live_class' && (!$item->resource_id || !Schema::hasTable('live_classes')
            || !DB::table('live_classes')->where('id', $item->resource_id)->exists())) {
            throw ValidationException::withMessages(['item' => 'This live-class pass must be linked to an existing Live Class ID by an administrator.']);
        }
        if ($type === 'live_class' && Schema::hasColumn('live_classes', 'branch_id')) {
            $liveClassBranchId = DB::table('live_classes')->where('id', $item->resource_id)->value('branch_id');
            $this->assertBranchAccess($user, $liveClassBranchId);
        }
        if ($item->available_from && now()->lt($item->available_from)) {
            throw ValidationException::withMessages(['item' => 'This service is not available yet.']);
        }
        if ($item->available_until && now()->gte($item->available_until)) {
            throw ValidationException::withMessages(['item' => 'This service is no longer available.']);
        }
        return [
            'type' => $type,
            'id' => $id,
            'title' => (string) $item->title,
            'price' => round((float) $item->price, 2),
            'currency' => strtoupper((string) $item->currency),
            'metadata' => ['sellable_id' => $id, 'resource_id' => $item->resource_id ? (int) $item->resource_id : null, 'entitlement' => json_decode((string) ($item->entitlement ?? '{}'), true) ?: []],
        ];
    }

    private function giftCourse(int $id, ?Authenticatable $user): array
    {
        $this->requireTable('course_gifts');
        if (!$user || !method_exists($user, 'getAuthIdentifier')) {
            throw ValidationException::withMessages(['item' => 'Sign in before adding a gift to your cart.']);
        }
        $gift = DB::table('course_gifts')->where('id', $id)->first();
        if (!$gift || (int) $gift->sender_user_id !== (int) $user->getAuthIdentifier()
            || $gift->status !== 'awaiting_payment'
            || ($gift->invoice_id ?? null) !== null
            || ($gift->payment_transaction_id ?? null) !== null
            || (Schema::hasColumn('course_gifts', 'commerce_order_item_id') && $gift->commerce_order_item_id !== null)
            || (Schema::hasColumn('course_gifts', 'unified_payment_transaction_id') && $gift->unified_payment_transaction_id !== null)
            || ($gift->expires_at && now()->greaterThan($gift->expires_at))
            || filled($gift->coupon_reservation_key ?? null)) {
            throw ValidationException::withMessages(['item' => 'This course gift draft is no longer available to add to your cart.']);
        }
        $recipientEmail = strtolower(trim((string) $gift->recipient_email));
        $senderEmail = strtolower(trim((string) ($user->getAttribute('email') ?? '')));
        if ($recipientEmail === '' || $recipientEmail === $senderEmail) {
            throw ValidationException::withMessages(['item' => 'A course gift must have a different, valid recipient email address.']);
        }

        $this->requireTable('courses');
        $course = DB::table('courses')->where('id', $gift->course_id)->where('status', 'published')->first();
        if (!$course) {
            throw ValidationException::withMessages(['item' => 'The gifted course is no longer published.']);
        }
        $courseBranchId = Schema::hasColumn('courses', 'branch_id') ? ($course->branch_id ?? null) : null;
        $this->assertBranchAccess($user, $courseBranchId);
        if ($courseBranchId !== null
            && Schema::hasColumn('course_gifts', 'recipient_user_id')
            && $gift->recipient_user_id !== null) {
            $recipientBranchId = null;
            if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('student-management')
                && Schema::hasTable('students')
                && Schema::hasColumn('students', 'branch_id')) {
                $recipientBranchId = DB::table('students')
                    ->where('user_id', (int) $gift->recipient_user_id)->value('branch_id');
            }
            if ($recipientBranchId === null && Schema::hasColumn('users', 'branch_id')) {
                $recipientBranchId = DB::table('users')
                    ->where('id', (int) $gift->recipient_user_id)->value('branch_id');
            }
            if ($recipientBranchId === null || (int) $recipientBranchId !== (int) $courseBranchId) {
                throw ValidationException::withMessages([
                    'item' => 'The selected recipient is not assigned to the branch that owns this course.',
                ]);
            }
        }
        if (Schema::hasTable('batches') && Schema::hasColumn('batches', 'status') && Schema::hasColumn('batches', 'course_id')
            && DB::table('batches')->where('course_id', $course->id)->where('status', 'open')->exists()) {
            throw ValidationException::withMessages(['item' => 'This course has an open batch. Contact admissions before gifting it.']);
        }

        $price = round((float) $gift->amount, 2);
        $subtotal = round((float) ($gift->subtotal_amount ?? $gift->amount), 2);
        $discount = round((float) ($gift->discount_amount ?? 0), 2);
        if ($price <= 0 || $subtotal <= 0 || $discount < 0 || $discount > $subtotal
            || abs(round($subtotal - $discount, 2) - $price) > 0.01
            || !filled($gift->redeem_token_hash) || !filled($gift->redeem_token_encrypted)
            || strtoupper((string) $gift->currency) !== strtoupper((string) config('lms.default_currency', 'BDT'))) {
            throw ValidationException::withMessages(['item' => 'The course gift amount, discount, token or currency is invalid for this store.']);
        }

        return $this->snapshot('gift_course', $id, 'Gift: ' . (string) $course->title, $price, [
            'course_gift_id' => $id,
            'course_id' => (int) $gift->course_id,
            'recipient_email' => $recipientEmail,
        ]);
    }

    private function giftVoucher(int $id, ?Authenticatable $user): array
    {
        $this->requireTable('course_gift_vouchers');
        if (!$user || !method_exists($user, 'getAuthIdentifier')) {
            throw ValidationException::withMessages(['item' => 'Sign in before adding a gift voucher to your cart.']);
        }
        $voucher = DB::table('course_gift_vouchers')->where('id', $id)->first();
        if (!$voucher || (int) $voucher->sender_user_id !== (int) $user->getAuthIdentifier()
            || $voucher->status !== 'awaiting_payment'
            || ($voucher->invoice_id ?? null) !== null
            || ($voucher->payment_transaction_id ?? null) !== null
            || (Schema::hasColumn('course_gift_vouchers', 'commerce_order_item_id') && $voucher->commerce_order_item_id !== null)
            || (Schema::hasColumn('course_gift_vouchers', 'unified_payment_transaction_id') && $voucher->unified_payment_transaction_id !== null)
            || ($voucher->expires_at && now()->greaterThan($voucher->expires_at))) {
            throw ValidationException::withMessages(['item' => 'This gift voucher draft is no longer available to add to your cart.']);
        }
        $recipientEmail = strtolower(trim((string) $voucher->recipient_email));
        $senderEmail = strtolower(trim((string) ($user->getAttribute('email') ?? '')));
        $price = round((float) $voucher->amount, 2);
        if ($recipientEmail === '' || $recipientEmail === $senderEmail || $price <= 0
            || abs(round((float) $voucher->remaining_amount, 2) - $price) > 0.01) {
            throw ValidationException::withMessages(['item' => 'The voucher recipient or amount is invalid.']);
        }

        return [
            'type' => 'gift_voucher',
            'id' => $id,
            'title' => 'Gift Voucher for ' . $recipientEmail,
            'price' => $price,
            'currency' => strtoupper((string) $voucher->currency),
            'metadata' => ['course_gift_voucher_id' => $id, 'recipient_email' => $recipientEmail],
        ];
    }

    private function assertBranchAccess(?Authenticatable $user, mixed $resourceBranchId): void
    {
        if ($resourceBranchId === null) {
            return; // Platform-wide catalogue items remain available to every branch.
        }
        $userBranchId = null;
        if ($user && method_exists($user, 'getAuthIdentifier')
            && app(\App\Core\Plugins\PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'branch_id')) {
            $profileBranchId = DB::table('students')->where('user_id', $user->getAuthIdentifier())->value('branch_id');
            if ($profileBranchId !== null) {
                $userBranchId = (int) $profileBranchId;
            }
        }
        if ($userBranchId === null && $user && method_exists($user, 'getAttribute')) {
            $accountBranchId = $user->getAttribute('branch_id');
            $userBranchId = $accountBranchId !== null ? (int) $accountBranchId : null;
        }
        if ($userBranchId === null || (int) $userBranchId !== (int) $resourceBranchId) {
            throw ValidationException::withMessages(['item' => 'This item is not available to your assigned branch.']);
        }
    }


    private function userBranchId(?Authenticatable $user): ?int
    {
        if (!$user || !method_exists($user, 'getAuthIdentifier')) return null;
        if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students') && Schema::hasColumn('students', 'branch_id')) {
            $branchId = DB::table('students')->where('user_id', $user->getAuthIdentifier())->value('branch_id');
            if ($branchId !== null) return (int) $branchId;
        }
        if (method_exists($user, 'getAttribute')) {
            $branchId = $user->getAttribute('branch_id');
            return $branchId !== null ? (int) $branchId : null;
        }
        return null;
    }

    private function snapshot(string $type, int $id, string $title, float $price, array $metadata): array
    {
        return ['type' => $type, 'id' => $id, 'title' => $title, 'price' => round($price, 2), 'currency' => strtoupper((string) config('lms.default_currency', 'BDT')), 'metadata' => $metadata];
    }

    private function requireTable(string $table): void
    {
        if (!Schema::hasTable($table)) {
            throw new RuntimeException("The required LMS module for {$table} is not installed or migrated.");
        }
    }
}
