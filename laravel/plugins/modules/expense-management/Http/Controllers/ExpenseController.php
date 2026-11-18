<?php

declare(strict_types=1);

namespace Plugins\Modules\ExpenseManagement\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Plugins\Modules\ExpenseManagement\Models\Expense;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureBranchAccess($actor);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'category' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:pending,approved,rejected'],
        ]);

        $query = $this->scopedQuery($actor)->with(['creator', 'approver'])->latest('expense_date');
        $this->applyFilters($query, $filters);
        $expenses = (clone $query)->paginate(25)->withQueryString();
        $approvedTotal = (clone $query)->where('status', 'approved')->sum('amount');
        $pendingTotal = (clone $query)->where('status', 'pending')->sum('amount');
        $categories = $this->scopedQuery($actor)->select('category')->distinct()->orderBy('category')->pluck('category');
        $recycleBin = $actor->hasRole('admin')
            ? Expense::onlyTrashed()->latest('deleted_at')->limit(20)->get()
            : collect();

        return view('expense-management::index', compact('expenses', 'approvedTotal', 'pendingTotal', 'categories', 'filters', 'recycleBin'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureBranchAccess($actor);
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'category' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'payment_method' => ['required', 'in:cash,bkash,nagad,bank,card,other'],
            'reference' => ['nullable', 'string', 'max:120'],
            'expense_date' => ['required', 'date'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $branchId = $this->resolvedBranchId($actor, $validated['branch_id'] ?? null);
        $receiptPath = $request->hasFile('receipt') ? $request->file('receipt')->store('expense-receipts', 'local') : null;
        $isAdmin = $actor->hasRole('admin');

        Expense::query()->create([
            ...$validated,
            'branch_id' => $branchId,
            'created_by' => $actor->id,
            'status' => $isAdmin ? 'approved' : 'pending',
            'approved_by' => $isAdmin ? $actor->id : null,
            'approved_at' => $isAdmin ? now() : null,
            'receipt_path' => $receiptPath,
        ]);

        return back()->with('status', $isAdmin ? 'Expense recorded and approved.' : 'Expense recorded and sent for approval.');
    }

    public function update(Request $request, int $expense): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureBranchAccess($actor);
        $record = $this->scopedQuery($actor)->findOrFail($expense);
        abort_unless($record->status === 'pending' || $actor->hasRole('admin'), 403, 'Approved or rejected expenses can only be edited by an administrator.');

        $validated = $request->validate([
            'category' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'payment_method' => ['required', 'in:cash,bkash,nagad,bank,card,other'],
            'reference' => ['nullable', 'string', 'max:120'],
            'expense_date' => ['required', 'date'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);
        if ($request->hasFile('receipt')) {
            if ($record->receipt_path) Storage::disk('local')->delete($record->receipt_path);
            $validated['receipt_path'] = $request->file('receipt')->store('expense-receipts', 'local');
        }
        $record->update($validated);

        return back()->with('status', 'Expense updated.');
    }

    public function review(Request $request, int $expense): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('admin'), 403, 'Only an administrator can approve or reject expenses.');
        $record = Expense::query()->findOrFail($expense);
        $validated = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $record->update([
            'status' => $validated['status'],
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'description' => trim((string) $record->description . (filled($validated['review_note'] ?? null) ? "\nReview: " . $validated['review_note'] : '')),
        ]);

        return back()->with('status', 'Expense ' . $validated['status'] . '.');
    }

    public function destroy(Request $request, int $expense): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureBranchAccess($actor);
        $record = $this->scopedQuery($actor)->findOrFail($expense);
        abort_unless($record->status === 'pending' || $actor->hasRole('admin'), 403);
        $record->delete();

        return back()->with('status', 'Expense moved to recycle bin.');
    }

    public function restore(Request $request, int $expense): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        Expense::onlyTrashed()->findOrFail($expense)->restore();

        return back()->with('status', 'Expense restored.');
    }

    public function downloadReceipt(Request $request, int $expense): StreamedResponse
    {
        $actor = $request->user();
        $this->ensureBranchAccess($actor);
        $record = $this->scopedQuery($actor)->findOrFail($expense);
        abort_unless($record->receipt_path && Storage::disk('local')->exists($record->receipt_path), 404);

        return Storage::disk('local')->download($record->receipt_path, basename($record->receipt_path), [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function financeSummary(Request $request): View
    {
        $actor = $request->user();
        $this->ensureBranchAccess($actor);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $collected = 0.0;
        $refunds = 0.0;
        if (app(PluginManager::class)->isEnabled('payment-management') && Schema::hasTable('payment_transactions')) {
            $payments = DB::table('payment_transactions')->where('status', 'settled')
                ->when($actor->hasRole('branch_manager') || $actor->hasRole('accountant'), fn ($q) => $q->where('branch_id', $actor->branch_id));
            if (!empty($filters['date_from'])) $payments->where('settled_at', '>=', $filters['date_from'] . ' 00:00:00');
            if (!empty($filters['date_to'])) $payments->where('settled_at', '<=', $filters['date_to'] . ' 23:59:59');
            $collected = (float) (clone $payments)->sum('amount');

            if (Schema::hasTable('payment_refunds')) {
                $refundQuery = DB::table('payment_refunds')
                    ->join('payment_transactions', 'payment_transactions.id', '=', 'payment_refunds.payment_transaction_id')
                    ->where('payment_transactions.status', 'refunded')
                    ->when($actor->hasRole('branch_manager') || $actor->hasRole('accountant'), fn ($q) => $q->where('payment_transactions.branch_id', $actor->branch_id));
                if (!empty($filters['date_from'])) $refundQuery->whereDate('payment_refunds.created_at', '>=', $filters['date_from']);
                if (!empty($filters['date_to'])) $refundQuery->whereDate('payment_refunds.created_at', '<=', $filters['date_to']);
                $refunds = (float) $refundQuery->sum('payment_refunds.amount');
            }
        }

        $expenseQuery = $this->scopedQuery($actor)->where('status', 'approved');
        if (!empty($filters['date_from'])) $expenseQuery->whereDate('expense_date', '>=', $filters['date_from']);
        if (!empty($filters['date_to'])) $expenseQuery->whereDate('expense_date', '<=', $filters['date_to']);
        $expenses = (float) $expenseQuery->sum('amount');

        return view('expense-management::finance', [
            'collected' => $collected,
            'refunds' => $refunds,
            'expenses' => $expenses,
            'netBalance' => $collected - $refunds - $expenses,
            'filters' => $filters,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $actor = $request->user();
        $this->ensureBranchAccess($actor);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'category' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:pending,approved,rejected'],
        ]);
        $query = $this->scopedQuery($actor)->with('creator')->orderBy('expense_date');
        $this->applyFilters($query, $filters);

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Date', 'Branch ID', 'Category', 'Title', 'Amount', 'Method', 'Reference', 'Status', 'Created By']);
            $query->chunk(500, function ($rows) use ($output): void {
                foreach ($rows as $row) {
                    fputcsv($output, [
                        $row->expense_date?->toDateString(), $row->branch_id, $row->category,
                        $row->title, $row->amount, $row->payment_method, $row->reference,
                        $row->status, $row->creator?->name,
                    ]);
                }
            });
            fclose($output);
        }, 'aec-expenses-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function scopedQuery($actor)
    {
        return Expense::query()->when(
            $actor->hasRole('branch_manager') || $actor->hasRole('accountant'),
            fn ($q) => $q->where('branch_id', $actor->branch_id ?: -1)
        );
    }

    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['date_from'])) $query->whereDate('expense_date', '>=', $filters['date_from']);
        if (!empty($filters['date_to'])) $query->whereDate('expense_date', '<=', $filters['date_to']);
        if (!empty($filters['category'])) $query->where('category', $filters['category']);
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
    }

    private function resolvedBranchId($actor, ?int $requested): ?int
    {
        if ($actor->hasRole('branch_manager') || $actor->hasRole('accountant')) return (int) $actor->branch_id;
        if ($requested !== null && app(PluginManager::class)->isEnabled('branch-management') && Schema::hasTable('branches')) {
            abort_unless(DB::table('branches')->where('id', $requested)->where('is_active', true)->exists(), 422);
        } elseif ($requested !== null) {
            abort(422, 'Enable Branch Management before assigning an expense to a branch.');
        }
        return $requested;
    }

    private function ensureBranchAccess($actor): void
    {
        if ($actor->hasRole('branch_manager') || $actor->hasRole('accountant')) {
            abort_unless((int) ($actor->branch_id ?? 0) > 0
                && app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists(),
                403,
                'Your account must be assigned to an active branch.'
            );
        }
    }
}
