<?php

declare(strict_types=1);

namespace Plugins\Modules\FeesManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
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
        $studentIntegrationEnabled = $this->studentIntegrationEnabled();
        $invoices = FeeInvoice::query()
            ->when($studentIntegrationEnabled, fn ($q) => $q->with('student.user'))
            ->with('category')
            ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->latest()
            ->paginate(25);
        $categories = FeeCategory::query()->orderBy('name')->get();

        return view('fees-management::index', compact('invoices', 'categories', 'studentIntegrationEnabled'));
    }

    public function storeInvoice(Request $request): RedirectResponse
    {
        abort_unless($this->studentIntegrationEnabled(), 503, 'Enable Student Management to issue student-linked invoices.');
        $actor = $request->user();
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'fee_category_id' => ['nullable', 'integer', 'exists:fee_categories,id'],
            'subtotal_amount' => ['required', 'numeric', 'min:1'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
        ]);

        $student = Student::query()->findOrFail((int) $validated['student_id']);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $student->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        $subtotal = round((float) $validated['subtotal_amount'], 2);
        $discount = min($subtotal, round((float) ($validated['discount_amount'] ?? 0), 2));
        $net = round($subtotal - $discount, 2);

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'INV-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10)),
            'student_id' => $student->id,
            'branch_id' => $student->branch_id,
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
    private function studentIntegrationEnabled(): bool
    {
        return app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students');
    }

}
