<?php

declare(strict_types=1);

namespace Plugins\Modules\FlashSale\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
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
                $salesQuery
                    ->where('courses.branch_id', $branchId ?: -1)
                    ->where('flash_sales.branch_id', $branchId ?: -1);
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

        $saleId = DB::table('flash_sales')->insertGetId($values + [
            'is_published' => $request->boolean('is_published'),
            'is_active' => $request->boolean('is_active'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(AuditLogger::class)->log('flash_sale.created', 'flash_sale', (string) $saleId, [
            'course_id' => (int) $values['course_id'],
            'branch_id' => $values['branch_id'] ?? null,
            'discount_type' => $values['discount_type'],
            'discount_value' => (float) $values['discount_value'],
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
        app(AuditLogger::class)->log('flash_sale.updated', 'flash_sale', (string) $sale, [
            'course_id' => (int) $values['course_id'],
            'branch_id' => $values['branch_id'] ?? null,
            'changed_fields' => array_keys($values),
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
        app(AuditLogger::class)->log('flash_sale.toggled', 'flash_sale', (string) $sale, [
            'was_active' => (bool) $row->is_active,
            'is_active' => !(bool) $row->is_active,
        ]);

        return back()->with('status', 'Campaign active state changed.');
    }

    public function destroy(Request $request, int $sale): RedirectResponse
    {
        $this->ensureAssignedBranch($request);
        $this->ensureCourseIntegration();
        $this->assertSaleInBranch($request, $sale);
        $record = DB::table('flash_sales')->where('id', $sale)->first();
        abort_unless($record, 404);
        DB::table('flash_sales')->where('id', $sale)->delete();
        app(AuditLogger::class)->log('flash_sale.deleted', 'flash_sale', (string) $sale, [
            'course_id' => (int) $record->course_id,
            'title' => (string) $record->title,
        ]);

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
        app(AuditLogger::class)->log('flash_sale.storefront_settings_updated', 'flash_sale_settings', '1', [
            'page_enabled' => (bool) $values['page_enabled'],
        ]);

        return back()->with('status', 'Page settings saved.');
    }

    public function publicIndex(Request $request): View
    {
        $this->ensureCourseIntegration();
        $settings = DB::table('flash_sale_settings')->first();
        abort_unless((bool) ($settings->page_enabled ?? true), 404);
        $settings ??= (object) ['page_title' => 'Flash Sale', 'page_enabled' => true];

        $viewerBranchId = null;
        if ($request->user()) {
            $accountBranchId = Schema::hasColumn('users', 'branch_id') && $request->user()->branch_id !== null
                ? (int) $request->user()->branch_id
                : null;
            $profileBranchId = null;

            if (app(PluginManager::class)->isEnabled('student-management')
                && Schema::hasTable('students') && Schema::hasColumn('students', 'branch_id')) {
                $profileValue = DB::table('students')
                    ->where('user_id', $request->user()->getAuthIdentifier())
                    ->value('branch_id');
                $profileBranchId = $profileValue !== null ? (int) $profileValue : null;
            }

            // Conflicting account/profile assignments must never grant access to
            // either branch's targeted campaigns. Global campaigns remain public.
            if ($accountBranchId !== null && $profileBranchId !== null
                && $accountBranchId !== $profileBranchId) {
                $viewerBranchId = -1;
            } else {
                $viewerBranchId = $profileBranchId ?? $accountBranchId;
            }
        }

        $eligibleCourses = DB::table('courses')
            ->where('courses.status', 'published')
            ->whereExists(function ($campaigns) use ($viewerBranchId): void {
                $campaigns->selectRaw('1')
                    ->from('flash_sales')
                    ->whereColumn('flash_sales.course_id', 'courses.id')
                    ->where('flash_sales.is_published', true)
                    ->where('flash_sales.is_active', true)
                    ->where('flash_sales.starts_at', '<=', now())
                    ->where('flash_sales.ends_at', '>', now())
                    ->where(function ($scope) use ($viewerBranchId): void {
                        $scope->whereNull('flash_sales.branch_id');
                        if ($viewerBranchId !== null) {
                            $scope->orWhere('flash_sales.branch_id', $viewerBranchId);
                        }
                    })
                    ->where(function ($scope): void {
                        $scope->whereNull('flash_sales.branch_id')
                            ->orWhereNull('courses.branch_id')
                            ->orWhereColumn('flash_sales.branch_id', 'courses.branch_id');
                    });
            })
            ->select('courses.id', 'courses.title', 'courses.slug')
            ->get();

        $pricing = app(\Plugins\Modules\FlashSale\Services\FlashSalePricingService::class);
        $sales = $eligibleCourses->map(function (object $course) use ($pricing, $viewerBranchId): ?object {
            $quote = $pricing->quote((int) $course->id, $viewerBranchId);
            if (!$quote['sale']) {
                return null;
            }

            $sale = $quote['sale'];
            $sale->course_title = $course->title;
            $sale->course_slug = $course->slug;
            $sale->course_price = $quote['subtotal'];
            $sale->sale_price = $quote['total'];
            $sale->discount_amount = $quote['discount'];
            $sale->discount_percent = $quote['subtotal'] > 0
                ? round(($quote['discount'] / $quote['subtotal']) * 100, 1)
                : 0.0;

            return $sale;
        })->filter()->values();

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
            abort_unless(
                $branchId > 0
                    && Schema::hasColumn('flash_sales', 'branch_id')
                    && DB::table('flash_sales')
                        ->join('courses', 'courses.id', '=', 'flash_sales.course_id')
                        ->where('flash_sales.id', $saleId)
                        ->where('flash_sales.branch_id', $branchId)
                        ->where('courses.branch_id', $branchId)
                        ->exists(),
                403,
                'Branch managers may only manage campaigns explicitly assigned to their branch and courses in that branch.'
            );
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
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'discount_type' => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);
        $values['cta_label'] = $values['cta_label'] ?? 'Explore offer';
        $values['priority'] = (int) ($values['priority'] ?? 0);
        if (!empty($values['branch_id'])) {
            abort_unless(Schema::hasTable('branches')
                && DB::table('branches')->where('id', (int) $values['branch_id'])->exists(),
                422,
                'Branch targeting requires an existing branch-management record.');
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
