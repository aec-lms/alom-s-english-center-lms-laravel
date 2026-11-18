<?php

declare(strict_types=1);

namespace Plugins\Modules\CommerceCart\Services;

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
    public function resolve(string $type, int $id): array
    {
        $type = strtolower(trim($type));
        return match ($type) {
            'course' => $this->course($id),
            'ebook' => $this->ebook($id),
            'mock_test', 'exam' => $this->exam($id),
            'subscription', 'live_class', 'other' => $this->sellable($type, $id),
            default => throw ValidationException::withMessages(['item' => 'This item type is not available in the unified store.']),
        };
    }

    private function course(int $id): array
    {
        $this->requireTable('courses');
        $course = DB::table('courses')->where('id', $id)->first();
        if (!$course || ($course->status ?? null) !== 'published') {
            throw ValidationException::withMessages(['item' => 'This course is not currently available for purchase.']);
        }
        $price = round((float) ($course->price ?? 0), 2);
        if (app(\\App\\Core\\Plugins\\PluginManager::class)->isEnabled('flash-sale') && Schema::hasTable('flash_sales')) {
            $sale = DB::table('flash_sales')->where('course_id', $id)->where('is_published', true)
                ->where('is_active', true)->where('starts_at', '<=', now())->where('ends_at', '>', now())
                ->orderByDesc('id')->first();
            if ($sale) {
                $discount = $sale->discount_type === 'percent'
                    ? $price * ((float) $sale->discount_value / 100)
                    : (float) $sale->discount_value;
                $price = round(max(0, $price - min($price, $discount)), 2);
            }
        }
        if ($price <= 0) throw ValidationException::withMessages(['item' => 'Free courses should be enrolled in directly, not purchased.']);
        return $this->snapshot('course', $id, (string) $course->title, $price, ['course_id' => $id]);
    }

    private function ebook(int $id): array
    {
        $this->requireTable('storefront_items');
        $item = DB::table('storefront_items')->where('id', $id)->where('type', 'ebook')->where('is_active', true)->first();
        if (!$item || (bool) ($item->is_free ?? false) || (float) $item->price <= 0) {
            throw ValidationException::withMessages(['item' => 'This paid eBook is not currently available.']);
        }
        return $this->snapshot('ebook', $id, (string) $item->title, (float) $item->price, ['storefront_item_id' => $id]);
    }

    private function exam(int $id): array
    {
        $this->requireTable('exams');
        $exam = DB::table('exams')->where('id', $id)->where('is_published', true)->first();
        if (!$exam) throw ValidationException::withMessages(['item' => 'This mock test is not currently available.']);
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

    private function sellable(string $type, int $id): array
    {
        $this->requireTable('commerce_sellables');
        $item = DB::table('commerce_sellables')->where('id', $id)->where('type', $type)->where('is_active', true)->first();
        if (!$item || (float) $item->price <= 0) {
            throw ValidationException::withMessages(['item' => 'This paid service is not currently available.']);
        }
        if ($type === 'live_class' && (!$item->resource_id || !Schema::hasTable('live_classes')
            || !DB::table('live_classes')->where('id', $item->resource_id)->exists())) {
            throw ValidationException::withMessages(['item' => 'This live-class pass must be linked to an existing Live Class ID by an administrator.']);
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
