<?php

declare(strict_types=1);

namespace Plugins\Modules\LibraryManagement\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\LibraryManagement\Models\LibraryBook;
use Plugins\Modules\LibraryManagement\Models\LibraryLoan;
use RuntimeException;

class LibraryController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PluginManager $plugins,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $books = Schema::hasTable('library_books')
            ? LibraryBook::query()
                ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))
                ->orderBy('title')
                ->get()
            : collect();

        $loans = Schema::hasTable('library_loans')
            ? LibraryLoan::query()
                ->with('book')
                ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))
                ->orderByDesc('id')
                ->limit(40)
                ->get()
            : collect();

        return view('library-management::index', compact('books', 'loans'));
    }

    public function storeBook(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $validated = $request->validate([
            'isbn' => ['required', 'string', 'max:40', 'unique:library_books,isbn'],
            'title' => ['required', 'string', 'max:200'],
            'author' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'max:80'],
            'total_copies' => ['required', 'integer', 'min:1', 'max:1000'],
            'shelf_location' => ['nullable', 'string', 'max:60'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        if ($actor && $actor->hasRole('branch_manager')) {
            $validated['branch_id'] = $actor->branch_id;
        }

        $book = LibraryBook::query()->create($validated + [
            'available_copies' => $validated['total_copies'],
        ]);

        $this->audit->log('library.book_added', 'library_book', (string) $book->id);

        return back()->with('status', "Book '{$book->title}' added to catalog.");
    }

    public function issueLoan(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $validated = $request->validate([
            'library_book_id' => ['required', 'integer', 'exists:library_books,id'],
            'borrower_name' => ['required', 'string', 'max:160'],
            'borrower_code' => ['required', 'string', 'max:60'],
            'due_at' => ['required', 'date'],
        ]);

        try {
            $loan = DB::transaction(function () use ($validated, $actor): LibraryLoan {
                $book = LibraryBook::query()->whereKey($validated['library_book_id'])->lockForUpdate()->firstOrFail();
                if ($actor && $actor->hasRole('branch_manager') && (int) $book->branch_id !== (int) $actor->branch_id) {
                    abort(403, 'Branch isolation violation.');
                }
                if ($book->available_copies < 1) {
                    throw new RuntimeException("No copies of '{$book->title}' are currently available.");
                }

                $book->decrement('available_copies');

                return LibraryLoan::query()->create([
                    'library_book_id' => $book->id,
                    'branch_id' => $book->branch_id,
                    'borrower_name' => $validated['borrower_name'],
                    'borrower_code' => $validated['borrower_code'],
                    'borrowed_at' => now()->toDateString(),
                    'due_at' => $validated['due_at'],
                    'status' => 'borrowed',
                    'fine_amount' => 0,
                ]);
            });

            $this->audit->log('library.loan_issued', 'library_loan', (string) $loan->id);
            return back()->with('status', "Book loaned to {$loan->borrower_name}.");
        } catch (RuntimeException $e) {
            return back()->withErrors(['library_book_id' => $e->getMessage()]);
        }
    }

    public function returnLoan(Request $request, LibraryLoan $loan): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $loan->branch_id !== (int) $actor->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        $dailyFine = max(0.0, (float) $this->plugins->getSetting('library-management', 'daily_fine_bdt', '10'));

        DB::transaction(function () use ($loan, $dailyFine): void {
            $lockedLoan = LibraryLoan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if ($lockedLoan->status === 'returned') {
                return;
            }

            $today = Carbon::today();
            $due = Carbon::parse($lockedLoan->due_at)->startOfDay();
            $overdueDays = $today->greaterThan($due) ? $due->diffInDays($today) : 0;
            $fine = round($overdueDays * $dailyFine, 2);

            $lockedLoan->update([
                'returned_at' => $today->toDateString(),
                'fine_amount' => $fine,
                'status' => 'returned',
            ]);

            $book = LibraryBook::query()->whereKey($lockedLoan->library_book_id)->lockForUpdate()->first();
            if ($book && $book->available_copies < $book->total_copies) {
                $book->increment('available_copies');
            }
        });

        $this->audit->log('library.loan_returned', 'library_loan', (string) $loan->id);

        return back()->with('status', 'Book return recorded cleanly.');
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }

        if ($actor->branch_id === null || !DB::table('branches')
            ->where('id', $actor->branch_id)
            ->where('is_active', true)
            ->exists()) {
            abort(403, 'Branch manager must be assigned to an active branch.');
        }
    }

}
