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
    public function index(): View
    {
        $courseIntegrationEnabled = $this->courseIntegrationEnabled();
        $sales = DB::table('flash_sales')
            ->when($courseIntegrationEnabled, fn ($query) => $query
                ->leftJoin('courses', 'courses.id', '=', 'flash_sales.course_id')
                ->select('flash_sales.*', 'courses.title as course_title'))
            ->when(!$courseIntegrationEnabled, fn ($query) => $query->select('flash_sales.*'))
            ->latest('flash_sales.id')
            ->paginate(25);

        $courses = $courseIntegrationEnabled
            ? DB::table('courses')->whereNull('deleted_at')->orderBy('title')->get()
            : collect();

        return view('flash-sale::index', [
            'sales' => $sales,
            'courses' => $courses,
            'courseIntegrationEnabled' => $courseIntegrationEnabled,
            'settings' => DB::table('flash_sale_settings')->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureCourseIntegration();
        $values = $this->validated($request);

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
        $this->ensureCourseIntegration();
        $values = $this->validated($request);
        abort_unless(DB::table('flash_sales')->where('id', $sale)->exists(), 404);

        DB::table('flash_sales')->where('id', $sale)->update($values + [
            'is_published' => $request->boolean('is_published'),
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Campaign updated.');
    }

    public function toggle(int $sale): RedirectResponse
    {
        $row = DB::table('flash_sales')->where('id', $sale)->first();
        abort_unless($row, 404);

        DB::table('flash_sales')->where('id', $sale)->update([
            'is_active' => !$row->is_active,
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Campaign active state changed.');
    }

    public function destroy(int $sale): RedirectResponse
    {
        abort_unless(DB::table('flash_sales')->where('id', $sale)->exists(), 404);
        DB::table('flash_sales')->where('id', $sale)->delete();

        return back()->with('status', 'Campaign deleted.');
    }

    public function settings(Request $request): RedirectResponse
    {
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
            ->select(
                'flash_sales.*',
                'courses.title as course_title',
                'courses.slug as course_slug',
                'courses.price as course_price'
            )
            ->orderBy('flash_sales.ends_at')
            ->get()
            ->map(function ($sale): object {
                $price = (float) $sale->course_price;
                $discount = $sale->discount_type === 'percent'
                    ? $price * (float) $sale->discount_value / 100
                    : (float) $sale->discount_value;
                $sale->sale_price = max(0, $price - min($price, $discount));

                return $sale;
            });

        return view('flash-sale::public', ['settings' => $settings, 'sales' => $sales]);
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
            'Enable the optional Course Management plugin to create or publish course flash sales.'
        );
    }

    private function validated(Request $request): array
    {
        $values = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'title' => ['required', 'string', 'max:160'],
            'discount_type' => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        if ($values['discount_type'] === 'percent' && (float) $values['discount_value'] > 100) {
            abort(422, 'Percent discount cannot exceed 100.');
        }

        return $values;
    }
}
