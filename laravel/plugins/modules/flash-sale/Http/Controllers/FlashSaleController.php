<?php

declare(strict_types=1);

namespace Plugins\Modules\FlashSale\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class FlashSaleController extends Controller
{
    public function index(Request $request): View
    {
        $this->ensureAssignedBranch($request);
        $courseIntegrationEnabled = $this->courseIntegrationEnabled();
        $salesQuery = DB::table('flash_sales');

        if ($courseIntegrationEnabled) {
            $salesQuery->leftJoin('courses', 'courses.id', '=', 'flash_sales.course_id')
                ->select('flash_sales.*', 'courses.title as course_title');

            if ($request->user()?->hasRole('branch_manager')) {
                $branchId = (int) ($request->user()->branch_id ?? 0);
                $salesQuery->where('courses.branch_id', $branchId ?: -1);
            }
        } else {
            $salesQuery->select('flash_sales.*');
            if ($request->user()?->hasRole('branch_manager')) {
                $salesQuery->whereRaw('1 = 0');
            }
        }

        $coursesQuery = $courseIntegrationEnabled ? DB::table('courses')->orderBy('title') : null;
        if ($coursesQuery && $request->user()?->hasRole('branch_manager')) {
            $branchId = (int) ($request->user()->branch_id ?? 0);
            $coursesQuery->where('branch_id', $branchId ?: -1);
        }

        return view('flash-sale::index', [
            'sales' => $salesQuery->latest('flash_sales.id')->paginate(25),
            'courses' => $coursesQuery?->get() ?? collect(),
            'courseIntegrationEnabled' => $courseIntegrationEnabled,
            'settings' => DB::table('flash_sale_settings')->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureAssignedBranch($request);
        $this->ensureCourseIntegration();
        $values = $this->validated($request);
        $this->assertCourseInBranch($request, (int) $values['course_id']);
        $values['branch_id'] = $request->user()?->hasRole('branch_manager') ? (int) $request->user()->branch_id : ($values['branch_id'] ?? null);

        DB::table('flash_sales')->insert($values + [
            'is_published' => $request->boolean('is_published'),
            'is_active' => $request->boolean('is_active'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Campaign created.');
    }

    public function update(Request $request, int $sale): RedirectResponse
    {
        $this->ensureAssignedBranch($request);
        $this->ensureCourseIntegration();
        $values = $this->validated($request);
        $this->assertSaleInBranch($request, $sale);
        $this->assertCourseInBranch($request, (int) $values['course_id']);
        $values['branch_id'] = $request->user()?->hasRole('branch_manager') ? (int) $request->user()->branch_id : ($values['branch_id'] ?? null);

        abort_unless(DB::table('flash_sales')->where('id', $sale)->exists(), 404);
        DB::table('flash_sales')->where('id', $sale)->update($values + [
            'is_published' => $request->boolean('is_published'),
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Campaign updated.');
    }

    public function toggle(Request $request, int $sale): RedirectResponse
    {
        $this->ensureAssignedBranch($request);
        $this->ensureCourseIntegration();
        $this->assertSaleInBranch($request, $sale);
        $row = DB::table('flash_sales')->where('id', $sale)->first();
        abort_unless($row, 404);

        DB::table('flash_sales')->where('id', $sale)->update([
            'is_active' => !$row->is_active,
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Campaign active state changed.');
    }

    public function destroy(Request $request, int $sale): RedirectResponse
    {
        $this->ensureAssignedBranch($request);
        $this->ensureCourseIntegration();
        $this->assertSaleInBranch($request, $sale);
        abort_unless(DB::table('flash_sales')->where('id', $sale)->exists(), 404);
        DB::table('flash_sales')->where('id', $sale)->delete();

        return back()->with('status', 'Campaign deleted.');
    }

    public function settings(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403, 'Only an administrator can change the institution-wide Flash Sale page settings.');
        $values = $request->validate([
            'page_enabled' => ['required', 'boolean'],
            'page_title' => ['required', 'string', 'max:160'],
        ]);

        DB::table('flash_sale_settings')->updateOrInsert(
            ['id' => 1],
            $values + ['created_at' => now(), 'updated_at' => now()]
        );

        return back()->with('status', 'Page settings saved.');
    }

    public function publicIndex(): View
    {
        $this->ensureCourseIntegration();
        $settings = DB::table('flash_sale_settings')->first();
        abort_unless($settings && $settings->page_enabled, 404);

        $sales = DB::table('flash_sales')
            ->join('courses', 'courses.id', '=', 'flash_sales.course_id')
            ->where('flash_sales.is_published', true)
            ->where('flash_sales.is_active', true)
            ->where('flash_sales.starts_at', '<=', now())
            ->where('flash_sales.ends_at', '>', now())
            ->where('courses.status', 'published')
            ->where(function ($scope): void {
                $scope->whereNull('flash_sales.branch_id')->orWhereColumn('flash_sales.branch_id', 'courses.branch_id');
            })
            ->select(
                'flash_sales.*',
                'courses.title as course_title',
                'courses.slug as course_slug',
                'courses.price as course_price'
            )
            ->orderByDesc('flash_sales.priority')
            ->orderBy('flash_sales.ends_at')
            ->get()
            ->map(function (object $sale): object {
                $price = (float) $sale->course_price;
                $discount = $sale->discount_type === 'percent'
                    ? $price * (float) $sale->discount_value / 100
                    : (float) $sale->discount_value;
                $sale->sale_price = round(max(0, $price - min($price, $discount)), 2);
                $sale->discount_amount = round(min($price, max(0, $discount)), 2);
                $sale->discount_percent = $price > 0 ? round(($sale->discount_amount / $price) * 100, 1) : 0.0;

                return $sale;
            });

        return view('flash-sale::public', [
            'settings' => $settings,
            'sales' => $sales,
            'currency' => strtoupper((string) config('lms.default_currency', 'BDT')),
        ]);
    }

    private function courseIntegrationEnabled(): bool
    {
        return app(PluginManager::class)->isEnabled('course-management')
            && Schema::hasTable('courses');
    }

    private function ensureCourseIntegration(): void
    {
        abort_unless(
            $this->courseIntegrationEnabled(),
            503,
            'Enable the optional Course Management plugin to create or publish course Flash Sales.'
        );
    }

    private function assertCourseInBranch(Request $request, int $courseId): void
    {
        $actor = $request->user();
        if ($actor?->hasRole('branch_manager')) {
            $branchId = (int) ($actor->branch_id ?? 0);
            abort_unless($branchId > 0 && DB::table('courses')->where('id', $courseId)->where('branch_id', $branchId)->exists(), 403);
        }
    }

    private function assertSaleInBranch(Request $request, int $saleId): void
    {
        $actor = $request->user();
        if ($actor?->hasRole('branch_manager')) {
            $branchId = (int) ($actor->branch_id ?? 0);
            abort_unless($branchId > 0 && DB::table('flash_sales')
                ->join('courses', 'courses.id', '=', 'flash_sales.course_id')
                ->where('flash_sales.id', $saleId)
                ->where('courses.branch_id', $branchId)
                ->exists(), 403);
        }
    }

    private function validated(Request $request): array
    {
        $values = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'title' => ['required', 'string', 'max:160'],
            'subtitle' => ['nullable', 'string', 'max:220'],
            'description' => ['nullable', 'string', 'max:5000'],
            'badge_text' => ['nullable', 'string', 'max:40'],
            'banner_image_url' => ['nullable', 'url:https', 'max:2048'],
            'cta_label' => ['nullable', 'string', 'max:40'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'discount_type' => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);
        $values['cta_label'] = $values['cta_label'] ?? 'Explore offer';
        $values['priority'] = (int) ($values['priority'] ?? 0);
        if (!empty($values['branch_id'])) {
            $courseBranch = DB::table('courses')->where('id', $values['course_id'])->value('branch_id');
            abort_unless($courseBranch === null || (int) $courseBranch === (int) $values['branch_id'], 422,
                'A branch-specific campaign must target a course available to that branch.');
        }

        if ($values['discount_type'] === 'percent' && (float) $values['discount_value'] > 100) {
            abort(422, 'Percent discount cannot exceed 100.');
        }

        return $values;
    }
    private function ensureAssignedBranch(Request $request): void
    {
        $actor = $request->user();
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }

        abort_unless(
            $actor->branch_id !== null
                && app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')
                    ->where('id', $actor->branch_id)
                    ->where('is_active', true)
                    ->exists(),
            403,
            'Branch manager must be assigned to an active branch.'
        );
    }

}
