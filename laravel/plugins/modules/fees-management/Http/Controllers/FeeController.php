<?php

declare(strict_types=1);

namespace Plugins\Modules\FeesManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
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
        $studentIntegration = app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students');
        $invoices = FeeInvoice::query()
            ->when($studentIntegration, fn ($q) => $q->with('student.user'))
            ->with('category')
            ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->latest()
            ->paginate(25);
        $categories = FeeCategory::query()->orderBy('name')->get();
        $students = $studentIntegration
            ? Student::query()->with('user')->orderBy('student_code')->get()
            : collect();

        return view('fees-management::index', compact('invoices', 'categories', 'students', 'studentIntegration'));
    }

    public function storeInvoice(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $studentIntegration = app(PluginManager::class)->isEnabled('student-management') && Schema::hasTable('students');
        $branchIntegration = app(PluginManager::class)->isEnabled('branch-management') && Schema::hasTable('branches');
        $branchRule = $branchIntegration
            ? ['nullable', 'integer', 'exists:branches,id,is_active,1']
            : ['nullable', 'integer'];
        $validated = $request->validate([
            'student_id' => $studentIntegration ? ['nullable', 'integer', 'exists:students,id'] : ['nullable', 'integer'],
            'payer_name' => ['required_without:student_id', 'nullable', 'string', 'max:160'],
            'payer_email' => ['nullable', 'email', 'max:180'],
            'payer_phone' => ['nullable', 'string', 'max:40'],
            'branch_id' => $branchRule,
            'fee_category_id' => ['nullable', 'integer', 'exists:fee_categories,id'],
            'subtotal_amount' => ['required', 'numeric', 'min:1'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
        ]);

        if (!$branchIntegration) { $validated['branch_id'] = null; }
        $student = $studentIntegration && !empty($validated['student_id'])
            ? Student::query()->with('user')->findOrFail((int) $validated['student_id'])
            : null;
        if ($actor && $actor->hasRole('branch_manager')) {
            if ($student && (int) $actor->branch_id !== (int) $student->branch_id) {
                abort(403, 'Branch isolation violation.');
            }
            $validated['branch_id'] = $actor->branch_id;
        } elseif ($student) {
            $validated['branch_id'] = $student->branch_id;
        }

        $subtotal = round((float) $validated['subtotal_amount'], 2);
        $discount = min($subtotal, round((float) ($validated['discount_amount'] ?? 0), 2));
        $net = round($subtotal - $discount, 2);

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'INV-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10)),
            'student_id' => $student?->id,
            'payer_name' => $student?->user?->name ?? ($validated['payer_name'] ?? null),
            'payer_email' => $student?->user?->email ?? ($validated['payer_email'] ?? null),
            'payer_phone' => $student?->user?->phone ?? ($validated['payer_phone'] ?? null),
            'branch_id' => $validated['branch_id'] ?? null,
            'fee_category_id' => $validated['fee_category_id'] ?? null,
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discount,
            'net_amount' => $net,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
            'due_date' => $validated['due_date'] ?? now()->addDays(7)->toDateString(),
        ]);

        $this->audit->log('fees.invoice_created', 'fee_invoice', (string) $invoice->id, ['net_amount' => $net]);

        return back()->with('status', "Invoice {$invoice->invoice_number} created.");
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }
        if ($actor->branch_id === null || !app(PluginManager::class)->isEnabled('branch-management') || !Schema::hasTable('branches')) {
            abort(403, 'Branch manager must be assigned to an active branch.');
        }
        $activeBranch = app('db')->table('branches')
            ->where('id', $actor->branch_id)
            ->where('is_active', true)
            ->exists();
        if (!$activeBranch) {
            abort(403, 'Branch manager access is disabled because the assigned branch is inactive.');
        }
    }
}
