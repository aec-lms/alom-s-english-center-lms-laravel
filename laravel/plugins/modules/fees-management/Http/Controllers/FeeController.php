<?php

declare(strict_types=1);

namespace Plugins\Modules\FeesManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\FeesManagement\Models\FeeCategory;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\StudentManagement\Models\Student;

class FeeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $studentIntegrationEnabled = $this->studentIntegrationEnabled();
        $invoices = FeeInvoice::query()
            ->when($studentIntegrationEnabled, fn ($q) => $q->with('student.user'))
            ->with('category')
            ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->latest()
            ->paginate(25);
        $categories = FeeCategory::query()->orderBy('name')->get();

        return view('fees-management::index', compact('invoices', 'categories', 'studentIntegrationEnabled'));
    }

    public function storeInvoice(Request $request): RedirectResponse
    {
        $studentIntegrationEnabled = $this->studentIntegrationEnabled();
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);

        $validated = $request->validate([
            'student_id' => [$studentIntegrationEnabled ? 'nullable' : 'prohibited', 'integer'],
            'payer_name' => ['required_without:student_id', 'nullable', 'string', 'max:160'],
            'payer_email' => ['required_without:student_id', 'nullable', 'email', 'max:180'],
            'payer_phone' => ['required_without:student_id', 'nullable', 'string', 'max:40'],
            'fee_category_id' => ['nullable', 'integer', 'exists:fee_categories,id'],
            'subtotal_amount' => ['required', 'numeric', 'min:0.01'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
        ]);

        $student = null;
        if (!empty($validated['student_id'])) {
            abort_unless($studentIntegrationEnabled, 503, 'Enable Student Management before linking an invoice to a student.');
            $student = Student::query()->with('user')->findOrFail((int) $validated['student_id']);
        }

        $branchId = $student?->branch_id ?? $actor?->branch_id;
        if ($actor && $actor->hasRole('branch_manager')) {
            abort_unless($student === null || (int) $student->branch_id === (int) $actor->branch_id, 403, 'Branch isolation violation.');
            $branchId = $actor->branch_id;
        }

        $subtotal = round((float) $validated['subtotal_amount'], 2);
        $discount = min($subtotal, round((float) ($validated['discount_amount'] ?? 0), 2));
        $net = round($subtotal - $discount, 2);
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'INV-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10)),
            'student_id' => $student?->id,
            'payer_name' => $student?->user?->name ?? trim((string) ($validated['payer_name'] ?? '')),
            'payer_email' => $student?->user?->email ?? strtolower(trim((string) ($validated['payer_email'] ?? ''))),
            'payer_phone' => $student?->user?->phone ?? trim((string) ($validated['payer_phone'] ?? '')),
            'branch_id' => $branchId,
            'fee_category_id' => $validated['fee_category_id'] ?? null,
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discount,
            'net_amount' => $net,
            'paid_amount' => 0,
            'currency' => strtoupper((string) config('lms.default_currency', 'BDT')),
            'status' => 'unpaid',
            'due_date' => $validated['due_date'] ?? now()->addDays(7)->toDateString(),
        ]);

        $this->audit->log('fees.invoice_created', 'fee_invoice', (string) $invoice->id, ['net_amount' => $net, 'student_id' => $student?->id]);

        return back()->with('status', "Invoice {$invoice->invoice_number} created.");
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }

        abort_unless(
            (int) ($actor->branch_id ?? 0) > 0
                && app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists(),
            403,
            'Branch manager must be assigned to an active branch.'
        );
    }

    private function studentIntegrationEnabled(): bool
    {
        return app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students');
    }
}
