<?php

declare(strict_types=1);

namespace Plugins\Modules\FlashSale\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FlashSalePricingService
{
    /**
     * Resolve one authoritative, currently eligible offer for a course.
     * A branch-specific campaign outranks a platform-wide campaign; then
     * explicit priority, earliest expiry and stable ID determine precedence.
     */
    public function quote(int $courseId, ?int $branchId = null): array
    {
        $course = DB::table('courses')->where('id', $courseId)->first();
        if (!$course) return ['sale' => null, 'subtotal' => 0.0, 'discount' => 0.0, 'total' => 0.0];

        $subtotal = round((float) ($course->price ?? 0), 2);
        if (!app(\App\Core\Plugins\PluginManager::class)->isEnabled('flash-sale')
            || !Schema::hasTable('flash_sales')) {
            return ['sale' => null, 'subtotal' => $subtotal, 'discount' => 0.0, 'total' => $subtotal];
        }

        $query = DB::table('flash_sales')
            ->where('course_id', $courseId)
            ->where('is_published', true)
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());

        if (Schema::hasColumn('flash_sales', 'branch_id')) {
            $query->where(function ($scope) use ($branchId): void {
                $scope->whereNull('branch_id');
                if ($branchId !== null) $scope->orWhere('branch_id', $branchId);
            });

            // A branch-targeted campaign must also be compatible with the
            // course's own branch. This keeps the quote resolver aligned with
            // the storefront and prevents cross-branch campaign leakage.
            if ($course->branch_id !== null) {
                $query->where(function ($scope) use ($course): void {
                    $scope->whereNull('branch_id')
                        ->orWhere('branch_id', (int) $course->branch_id);
                });
            }

            $query->orderByRaw('CASE WHEN branch_id IS NULL THEN 0 ELSE 1 END DESC');
        }

        $sale = $query->orderByDesc(Schema::hasColumn('flash_sales', 'priority') ? 'priority' : 'id')
            ->orderBy('ends_at')
            ->orderByDesc('id')
            ->first();

        if (!$sale) return ['sale' => null, 'subtotal' => $subtotal, 'discount' => 0.0, 'total' => $subtotal];

        $rawDiscount = $sale->discount_type === 'percent'
            ? $subtotal * ((float) $sale->discount_value / 100)
            : (float) $sale->discount_value;
        $discount = round(min($subtotal, max(0, $rawDiscount)), 2);

        return [
            'sale' => $sale,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => round(max(0, $subtotal - $discount), 2),
        ];
    }
}
