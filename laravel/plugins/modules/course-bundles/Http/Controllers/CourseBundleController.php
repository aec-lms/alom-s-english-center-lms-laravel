<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseBundles\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\CourseBundles\Models\CourseBundle;
use Plugins\Modules\CourseBundles\Models\CourseBundleOrder;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;

class CourseBundleController extends Controller
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function index(Request $request): View
    {
        $this->assertCourseIntegration();
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $bundles = CourseBundle::query()
            ->withCount('orders')
            ->with('items')
            ->when($actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->latest()
            ->paginate(20);
        $courses = $this->selectableCourses($actor);

        return view('course-bundles::admin-index', compact('bundles', 'courses'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $validated = $this->validateBundle($request, $actor);

        DB::transaction(function () use ($actor, $validated): void {
            $bundle = CourseBundle::query()->create([
                'branch_id' => $validated['branch_id'],
                'created_by' => $actor->id,
                'title' => $validated['title'],
                'slug' => $this->uniqueSlug($validated['title']),
                'description' => $validated['description'] ?? null,
                'price' => $validated['price'],
                'status' => $validated['status'],
            ]);
            $this->syncItems($bundle, $validated['course_ids']);
        });

        return back()->with('status', 'Course bundle created.');
    }

    public function update(Request $request, int $bundle): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $record = CourseBundle::query()->when($actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))->findOrFail($bundle);
        $validated = $this->validateBundle($request, $actor, $record->id);

        DB::transaction(function () use ($record, $validated, $actor): void {
            $lockedRecord = CourseBundle::query()
                ->when($actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))
                ->lockForUpdate()
                ->findOrFail($record->id);
            $lockedRecord->update([
                'branch_id' => $validated['branch_id'],
                'title' => $validated['title'],
                'slug' => $this->uniqueSlug($validated['title'], $record->id),
                'description' => $validated['description'] ?? null,
                'price' => $validated['price'],
                'status' => $validated['status'],
            ]);
            $this->syncItems($lockedRecord, $validated['course_ids']);
        });

        return back()->with('status', 'Course bundle updated. Existing orders retain their original course snapshots.');
    }

    public function archive(Request $request, int $bundle): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $record = CourseBundle::query()->when($actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))->findOrFail($bundle);
        $record->update(['status' => 'archived']);

        return back()->with('status', 'Course bundle archived. Purchase history was preserved.');
    }

    public function publicIndex(Request $request): View
    {
        $this->assertCourseIntegration();
        $bundles = $this->publicBundleQuery($request->user())
            ->with('items')
            ->latest()
            ->paginate(12);

        return view('course-bundles::catalog', compact('bundles'));
    }

    public function show(Request $request, string $slug): View
    {
        $this->assertCourseIntegration();
        $bundle = $this->publicBundleQuery($request->user())
            ->where('slug', $slug)
            ->with('items')
            ->firstOrFail();
        $courses = $this->publishedBundleCourses($bundle);
        abort_unless($courses->count() >= 2, 404);
        $purchaseAvailable = !Schema::hasTable('batches') || !Schema::hasColumn('batches', 'status')
            || !DB::table('batches')->whereIn('course_id', $courses->pluck('id'))->where('status', 'open')->exists();

        return view('course-bundles::show', compact('bundle', 'courses', 'purchaseAvailable'));
    }

    public function checkout(Request $request, string $slug): RedirectResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403, 'Only students can purchase course bundles.');
        $this->assertCourseIntegration();
        $bundle = CourseBundle::query()->where('slug', $slug)->where('status', 'published')->with('items')->firstOrFail();
        $courses = $this->publishedBundleCourses($bundle);
        abort_unless($courses->count() >= 2 && $courses->count() === $bundle->items->count(), 409, 'A course in this bundle is no longer available.');

        $branchIds = $courses->pluck('branch_id')->filter()->unique();
        abort_if($branchIds->count() > 1, 422, 'A bundle cannot combine courses from different branches.');
        if ($bundle->branch_id !== null && $student->branch_id !== null) {
            abort_unless((int) $bundle->branch_id === (int) $student->branch_id, 403, 'This bundle is outside your assigned branch.');
        }
        if (Schema::hasTable('batches') && Schema::hasColumn('batches', 'status')) {
            abort_if(DB::table('batches')->whereIn('course_id', $courses->pluck('id'))->where('status', 'open')->exists(), 409, 'A course in this bundle has an open batch. Contact the admissions office to enroll in the correct batch.');
        }
        $branchId = $bundle->branch_id ?? $branchIds->first() ?? $student->branch_id;
        if ($branchId !== null) {
            abort_unless(
                $student->branch_id !== null && (int) $branchId === (int) $student->branch_id,
                403,
                'A branch-specific bundle is available only to students assigned to that branch.'
            );
        }

        $existing = DB::table('enrollments')
            ->where('user_id', $student->id)
            ->whereIn('course_id', $courses->pluck('id'))
            ->whereIn('status', ['pending', 'active'])
            ->exists();
        abort_if($existing, 409, 'You already have a pending or active enrollment in one or more courses in this bundle.');

        $order = DB::transaction(function () use ($bundle, $courses, $student): CourseBundleOrder {
            // Serialize checkouts for this student, then re-check enrollments while locked.
            DB::table('users')->where('id', $student->id)->lockForUpdate()->first();
            $lockedBundle = CourseBundle::query()->lockForUpdate()->findOrFail($bundle->id);
            abort_unless($lockedBundle->status === 'published', 409, 'This bundle is no longer available.');

            $lockedItemIds = DB::table('course_bundle_courses')
                ->where('bundle_id', $lockedBundle->id)
                ->orderBy('position')
                ->lockForUpdate()
                ->pluck('course_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $expectedIds = $courses->pluck('id')->map(fn ($id): int => (int) $id)->all();
            abort_unless($lockedItemIds === $expectedIds && count($lockedItemIds) >= 2, 409, 'The bundle contents changed. Please review the bundle and try again.');

            $lockedCourses = DB::table('courses')
                ->whereIn('id', $lockedItemIds)
                ->where('status', 'published')
                ->get(['id', 'title', 'branch_id'])
                ->keyBy('id');
            abort_unless($lockedCourses->count() === count($lockedItemIds), 409, 'A course in this bundle is no longer available.');
            $lockedBranchIds = $lockedCourses->pluck('branch_id')->filter()->unique();
            abort_if($lockedBranchIds->count() > 1, 422, 'A bundle cannot combine courses from different branches.');
            if ($lockedBundle->branch_id !== null && $lockedBranchIds->isNotEmpty()) {
                abort_unless(
                    $lockedBranchIds->count() === 1 && (int) $lockedBranchIds->first() === (int) $lockedBundle->branch_id,
                    409,
                    'The bundle branch no longer matches its included courses.'
                );
            }
            $branchId = $lockedBundle->branch_id ?? $lockedBranchIds->first() ?? $student->branch_id;
            if ($branchId !== null) {
                abort_unless(
                    $student->branch_id !== null && (int) $branchId === (int) $student->branch_id,
                    403,
                    'A branch-specific bundle is available only to students assigned to that branch.'
                );
            }

            $existing = DB::table('enrollments')
                ->where('user_id', $student->id)
                ->whereIn('course_id', $lockedItemIds)
                ->whereIn('status', ['pending', 'active'])
                ->lockForUpdate()
                ->exists();
            abort_if($existing, 409, 'You already have a pending or active enrollment in one or more courses in this bundle.');

            if (Schema::hasTable('batches') && Schema::hasColumn('batches', 'status')) {
                abort_if(DB::table('batches')->whereIn('course_id', $lockedItemIds)->where('status', 'open')->exists(), 409, 'A course in this bundle has an open batch. Contact the admissions office to enroll in the correct batch.');
            }

            $orderedCourses = collect($lockedItemIds)->map(fn (int $id) => $lockedCourses->get($id));
            $price = round((float) $lockedBundle->price, 2);
            $invoice = null;
            if ($price > 0) {
                abort_unless($this->plugins->isEnabled('fees-management') && Schema::hasTable('fee_invoices')
                    && Schema::hasColumn('enrollments', 'invoice_id'), 503,
                    'Fees Management and invoice-linked enrollments must be enabled for paid bundle checkout.');
                $studentProfile = $this->plugins->isEnabled('student-management') && Schema::hasTable('students')
                    ? DB::table('students')->where('user_id', $student->id)->first()
                    : null;
                $invoice = FeeInvoice::query()->create([
                    'invoice_number' => 'BND-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)),
                    'student_id' => $studentProfile->id ?? null,
                    'payer_name' => $student->name,
                    'payer_email' => $student->email,
                    'payer_phone' => $student->phone,
                    'branch_id' => $branchId,
                    'subtotal_amount' => $price,
                    'discount_amount' => 0,
                    'net_amount' => $price,
                    'paid_amount' => 0,
                    'currency' => strtoupper((string) config('lms.default_currency', 'BDT')),
                    'status' => 'unpaid',
                    'due_date' => now()->addDays(7)->toDateString(),
                ]);
            }

            $order = CourseBundleOrder::query()->create([
                'bundle_id' => $lockedBundle->id,
                'user_id' => $student->id,
                'invoice_id' => $invoice?->id,
                'branch_id' => $branchId,
                'bundle_title' => $lockedBundle->title,
                'amount' => $price,
                'currency' => $invoice?->currency ?? strtoupper((string) config('lms.default_currency', 'BDT')),
                'status' => $price > 0 ? 'awaiting_payment' : 'completed',
                'purchased_at' => $price > 0 ? null : now(),
            ]);

            foreach ($orderedCourses as $position => $course) {
                DB::table('course_bundle_order_courses')->insert([
                    'order_id' => $order->id,
                    'course_id' => $course->id,
                    'course_title' => $course->title,
                    'position' => $position + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $enrollment = DB::table('enrollments')
                    ->where('user_id', $student->id)
                    ->where('course_id', $course->id)
                    ->first();
                $enrollmentData = [
                    'user_id' => $student->id,
                    'course_id' => $course->id,
                    'branch_id' => $branchId,
                    'invoice_id' => $invoice?->id,
                    'status' => $price > 0 ? 'pending' : 'active',
                    'updated_at' => now(),
                ];
                if ($enrollment) {
                    DB::table('enrollments')->where('id', $enrollment->id)->update($enrollmentData);
                } else {
                    $enrollmentData['created_at'] = now();
                    DB::table('enrollments')->insert($enrollmentData);
                }
            }

            return $order;
        });

        return redirect()->route('course-bundles.orders.show', $order->id)
            ->with('status', $order->amount > 0 ? 'Bundle reserved. Complete invoice payment to activate all included courses.' : 'Free bundle activated. You are enrolled in every included course.');
    }

    public function myOrders(Request $request): View
    {
        $orders = CourseBundleOrder::query()->where('user_id', $request->user()->id)->with('items')->latest()->paginate(20);
        foreach ($orders as $order) {
            $invoice = $order->invoice_id && Schema::hasTable('fee_invoices')
                ? DB::table('fee_invoices')->where('id', $order->invoice_id)->first()
                : null;
            $this->syncOrderPaymentStatus($order, $invoice);
        }
        return view('course-bundles::orders', compact('orders'));
    }

    public function orderShow(Request $request, int $order): View
    {
        $record = CourseBundleOrder::query()->where('user_id', $request->user()->id)->with('items')->findOrFail($order);
        $invoice = $record->invoice_id && Schema::hasTable('fee_invoices')
            ? DB::table('fee_invoices')->where('id', $record->invoice_id)->first()
            : null;
        $this->syncOrderPaymentStatus($record, $invoice);
        $paymentPluginAvailable = $this->plugins->isEnabled('universal-payment')
            && Schema::hasTable('universal_payment_transactions');
        $enabledGateways = array_filter(array_map('trim', explode(',', (string) $this->plugins->getSetting(
            'universal-payment',
            'enabled_gateways',
            'sslcommerz,bkash,nagad,rocket,card,bank_cash'
        ))));
        $offlinePaymentsEnabled = (string) $this->plugins->getSetting('universal-payment', 'allow_offline_payments', '1') === '1';
        $paymentReady = $paymentPluginAvailable
            && $offlinePaymentsEnabled
            && in_array('bank_cash', $enabledGateways, true);

        return view('course-bundles::order-show', compact('record', 'invoice', 'paymentReady', 'paymentPluginAvailable'));
    }

    private function syncOrderPaymentStatus(CourseBundleOrder $order, $invoice): void
    {
        if ($invoice === null) return;
        $status = match ($invoice->status) {
            'paid' => 'completed',
            'partial' => 'partial_payment',
            'cancelled' => 'cancelled',
            default => 'awaiting_payment',
        };
        if ($order->status !== $status) {
            $order->update([
                'status' => $status,
                'purchased_at' => $status === 'completed' ? ($order->purchased_at ?? now()) : $order->purchased_at,
            ]);
        }
    }

    private function validateBundle(Request $request, $actor, ?int $ignoreId = null): array
    {
        $this->assertCourseIntegration();
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'status' => ['required', 'in:draft,published,archived'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'course_ids' => ['required', 'array', 'min:2', 'max:20'],
            'course_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $validated['course_ids'])));
        $query = DB::table('courses')->whereIn('id', $ids)->where('status', 'published');
        if ($actor->hasRole('branch_manager')) {
            $query->where('branch_id', $actor->branch_id ?: -1);
        }
        $selected = $query->get(['id', 'branch_id']);
        abort_unless($selected->count() === count($ids), 422, 'Select only existing published courses in your permitted branch.');
        $branches = $selected->pluck('branch_id')->filter()->unique();
        abort_if($branches->count() > 1, 422, 'A bundle cannot combine courses from different branches.');

        if (Schema::hasTable('batches') && Schema::hasColumn('batches', 'status')) {
            $openBatchCourseIds = DB::table('batches')->whereIn('course_id', $ids)->where('status', 'open')->pluck('course_id')->all();
            abort_if($openBatchCourseIds !== [], 422, 'Courses with open batches cannot be added to a combo bundle.');
        }

        $branchId = $actor->hasRole('branch_manager') ? (int) $actor->branch_id : (isset($validated['branch_id']) ? (int) $validated['branch_id'] : $branches->first());
        if (!$actor->hasRole('branch_manager') && $branchId !== null) {
            abort_unless(
                $this->plugins->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $branchId)->where('is_active', true)->exists(),
                422,
                'Select an active branch.'
            );
        }
        if ($branches->isNotEmpty() && $branchId !== null) {
            abort_unless($branches->count() === 1 && (int) $branches->first() === (int) $branchId, 422, 'Bundle and courses must belong to the same branch.');
        }
        $validated['course_ids'] = $ids;
        $validated['branch_id'] = $branchId;

        return $validated;
    }

    private function selectableCourses($actor)
    {
        $query = DB::table('courses')->where('status', 'published')->orderBy('title');
        if ($actor->hasRole('branch_manager')) {
            $query->where('branch_id', $actor->branch_id ?: -1);
        }
        return $query->get(['id', 'title', 'branch_id', 'price']);
    }

    private function publishedBundleCourses(CourseBundle $bundle)
    {
        $ids = $bundle->items->pluck('course_id')->all();
        $available = DB::table('courses')->whereIn('id', $ids)->where('status', 'published')->get()->keyBy('id');
        return collect($ids)->map(fn ($id) => $available->get($id))->filter()->values();
    }

    private function syncItems(CourseBundle $bundle, array $ids): void
    {
        DB::table('course_bundle_courses')->where('bundle_id', $bundle->id)->delete();
        foreach (array_values($ids) as $position => $id) {
            DB::table('course_bundle_courses')->insert([
                'bundle_id' => $bundle->id,
                'course_id' => $id,
                'position' => $position + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'course-bundle';
        $slug = $base;
        $suffix = 2;
        while (CourseBundle::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base . '-' . $suffix++;
        }
        return $slug;
    }

    private function publicBundleQuery($actor)
    {
        return CourseBundle::query()
            ->where('status', 'published')
            ->where(function ($query) use ($actor): void {
                $query->whereNull('branch_id');
                if ($actor && $actor->branch_id !== null) {
                    $query->orWhere('branch_id', (int) $actor->branch_id);
                }
            });
    }

    private function assertCourseIntegration(): void
    {
        abort_unless($this->plugins->isEnabled('course-management') && Schema::hasTable('courses'), 503, 'Enable Course Management to use course bundles.');
    }

    private function ensureAssignedBranch($actor): void
    {
        if ($actor && $actor->hasRole('branch_manager')) {
            abort_unless((int) ($actor->branch_id ?? 0) > 0
                && $this->plugins->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists(),
                403,
                'Branch manager must be assigned to an active branch.'
            );
        }
    }
}
