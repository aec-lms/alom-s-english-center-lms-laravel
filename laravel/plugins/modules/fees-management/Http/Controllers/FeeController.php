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
use Illuminate\Support\Facades\Mail;
use Throwable;
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
        $filters = $request->validate([
            'status' => ['nullable', 'in:unpaid,partial,paid,overdue'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $base = FeeInvoice::query()
            ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->when($filters['from'] ?? null, fn ($q,$v) => $q->whereDate('created_at','>=',$v))
            ->when($filters['to'] ?? null, fn ($q,$v) => $q->whereDate('created_at','<=',$v))
            ->when($filters['q'] ?? null, function ($q,$v): void { $term='%'.$v.'%'; $q->where(fn($nested)=>$nested->where('invoice_number','like',$term)->orWhere('payer_name','like',$term)->orWhere('payer_email','like',$term)->orWhere('payer_phone','like',$term)); });
        $query=(clone $base)->when(($filters['status'] ?? null)==='overdue',fn($q)=>$q->whereIn('status',['unpaid','partial'])->whereDate('due_date','<',now()->toDateString()))
            ->when(!empty($filters['status']) && $filters['status']!=='overdue',fn($q)=>$q->where('status',$filters['status']));
        $invoices = $query->when($studentIntegrationEnabled, fn ($q) => $q->with('student.user'))->with('category')->latest()->paginate(25)->withQueryString();
        $totals = (clone $base)->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('COALESCE(SUM(net_amount),0) as billed')
            ->selectRaw('COALESCE(SUM(paid_amount),0) as collected')
            ->selectRaw('COALESCE(SUM(net_amount-paid_amount),0) as outstanding')
            ->first();
        $overdueCount = (clone $base)->whereIn('status',['unpaid','partial'])->whereDate('due_date','<',now()->toDateString())->count();
        $categories = FeeCategory::query()->orderBy('name')->get();

        return view('fees-management::index', compact('invoices', 'categories', 'studentIntegrationEnabled', 'filters', 'totals', 'overdueCount'));
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


    public function waiveInvoice(Request $request, FeeInvoice $invoice): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $invoice->branch_id) abort(403, 'Branch isolation violation.');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $adjustment = DB::transaction(function () use ($invoice, $data, $actor) {
            $locked = FeeInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $remaining = round((float) $locked->net_amount - (float) $locked->paid_amount, 2);
            $amount = round((float) $data['amount'], 2);
            abort_if($locked->status === 'cancelled' || $remaining <= 0, 409, 'Only invoices with an outstanding balance can be waived.');
            abort_if($amount > $remaining, 422, 'Waiver cannot exceed the unpaid invoice balance.');
            $oldNet = round((float) $locked->net_amount, 2);
            $newNet = round($oldNet - $amount, 2);
            $locked->update([
                'discount_amount' => round((float) $locked->discount_amount + $amount, 2),
                'net_amount' => $newNet,
                'status' => (float) $locked->paid_amount >= $newNet ? 'paid' : ((float) $locked->paid_amount > 0 ? 'partial' : 'unpaid'),
            ]);
            return DB::table('fee_invoice_adjustments')->insertGetId([
                'fee_invoice_id' => $locked->id, 'approved_by' => $actor?->id,
                'amount' => $amount, 'previous_net_amount' => $oldNet, 'new_net_amount' => $newNet,
                'adjustment_type' => 'waiver', 'reason' => $data['reason'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->audit->log('fees.invoice_waived', 'fee_invoice_adjustment', (string) $adjustment, [
            'invoice_id' => $invoice->id, 'amount' => round((float) $data['amount'], 2), 'reason' => $data['reason'],
        ]);
        return back()->with('status', 'Fee waiver applied and recorded in the adjustment ledger.');
    }

    public function sendDueReminder(Request $request, FeeInvoice $invoice): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $invoice->branch_id) abort(403, 'Branch isolation violation.');
        abort_unless(in_array($invoice->status, ['unpaid', 'partial'], true), 409, 'Only unpaid or partially paid invoices can receive reminders.');
        $email = trim((string) $invoice->payer_email);
        abort_if($email === '', 422, 'The invoice has no payer email address.');
        $balance = round((float) $invoice->net_amount - (float) $invoice->paid_amount, 2);
        abort_if($balance <= 0, 409, 'This invoice has no outstanding balance.');

        $body = "Payment reminder for invoice {$invoice->invoice_number}\n"
            . "Outstanding balance: {$invoice->currency} " . number_format($balance, 2) . "\n"
            . "Due date: " . ($invoice->due_date?->toDateString() ?? 'Please contact the office') . "\n"
            . "Please contact Alom's English Center to arrange payment. If you have already paid, please ignore this reminder.";

        try {
            Mail::raw($body, function ($message) use ($email, $invoice): void {
                $message->to($email)->subject('Payment reminder — ' . $invoice->invoice_number);
            });
            DB::table('fee_invoice_reminders')->insert([
                'fee_invoice_id' => $invoice->id, 'sent_by' => $actor?->id,
                'recipient_email' => $email, 'status' => 'sent', 'failure_reason' => null,
                'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            DB::table('fee_invoice_reminders')->insert([
                'fee_invoice_id' => $invoice->id, 'sent_by' => $actor?->id,
                'recipient_email' => $email, 'status' => 'failed',
                'failure_reason' => mb_substr($exception->getMessage(), 0, 2000),
                'sent_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            report($exception);
            return back()->withErrors(['reminder' => 'Reminder could not be sent. Check mail configuration and try again.']);
        }

        $this->audit->log('fees.due_reminder_sent', 'fee_invoice', (string) $invoice->id, [
            'recipient_email' => $email, 'outstanding_balance' => $balance,
        ]);
        return back()->with('status', 'Payment reminder sent and logged.');
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
