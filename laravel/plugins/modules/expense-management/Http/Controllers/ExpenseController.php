<?php

declare(strict_types=1);

namespace Plugins\\Modules\\ExpenseManagement\\Http\\Controllers;

use App\\Core\\Plugins\\PluginManager;
use App\\Core\\Security\\AuditLogger;
use App\\Http\\Controllers\\Controller;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;
use Illuminate\\View\\View;
use Plugins\\Modules\\ExpenseManagement\\Models\\Expense;

class ExpenseController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureBranchAccess($actor);
        $query = Expense::query()->with('creator')->latest('expense_date');
        if ($actor->hasRole('branch_manager')) {
            $query->where('branch_id', (int) $actor->branch_id);
        }

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'category' => ['nullable', 'string', 'max:80'],
        ]);
        $query->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('expense_date', '>=', $date));
        $query->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('expense_date', '<=', $date));
        $query->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category));

        $total = (clone $query)->sum('amount');
        $categories = Expense::query()
            ->when($actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', (int) $actor->branch_id))
            ->select('category')->distinct()->orderBy('category')->pluck('category');

        return view('expense-management::index', [
            'expenses' => $query->paginate(25)->withQueryString(),
            'total' => (float) $total,
            'categories' => $categories,
            'filters' => $filters,
            'isBranchManager' => $actor->hasRole('branch_manager'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureBranchAccess($actor);
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'category' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'payment_method' => ['required', 'in:cash,bkash,nagad,bank,card,other'],
            'reference' => ['nullable', 'string', 'max:120'],
            'expense_date' => ['required', 'date'],
        ]);
        if ($actor->hasRole('branch_manager')) {
            $data['branch_id'] = (int) $actor->branch_id;
        } elseif (!empty($data['branch_id'])) {
            abort_unless(Schema::hasTable('branches') && DB::table('branches')->where('id', $data['branch_id'])->where('is_active', true)->exists(), 422, 'Select an active branch.');
        }

        $expense = Expense::query()->create([...$data, 'created_by' => $actor->id]);
        $this->audit->log('expenses.created', 'expense', (string) $expense->id, ['amount' => (float) $expense->amount]);
        return back()->with('status', 'Expense recorded successfully.');
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorizeExpense($request, $expense);
        $data = $request->validate([
            'category' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'payment_method' => ['required', 'in:cash,bkash,nagad,bank,card,other'],
            'reference' => ['nullable', 'string', 'max:120'],
            'expense_date' => ['required', 'date'],
        ]);
        $expense->update($data);
        $this->audit->log('expenses.updated', 'expense', (string) $expense->id);
        return back()->with('status', 'Expense updated successfully.');
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorizeExpense($request, $expense);
        $expense->delete();
        $this->audit->log('expenses.deleted', 'expense', (string) $expense->id);
        return back()->with('status', 'Expense deleted successfully.');
    }

    private function ensureBranchAccess($actor): void
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

    private function authorizeExpense(Request $request, Expense $expense): void
    {
        $actor = $request->user();
        $this->ensureBranchAccess($actor);
        if ($actor->hasRole('branch_manager')) {
            abort_unless((int) $expense->branch_id === (int) $actor->branch_id, 404);
            return;
        }
        abort_unless($actor->hasRole('admin'), 403);
    }
}
