<?php

declare(strict_types=1);

namespace Plugins\Modules\NoticeBoard\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Plugins\Modules\NoticeBoard\Models\Notice;

class NoticeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $notices = Notice::query()
            ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where(fn ($sub) => $sub->whereNull('branch_id')->orWhere('branch_id', $actor->branch_id)))
            ->orderByDesc('is_pinned')
            ->latest()
            ->paginate(20);

        return view('notice-board::index', compact('notices'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string'],
            'audience_role' => ['required', 'in:all,student,teacher,guardian'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        if ($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null) {
            $validated['branch_id'] = $actor->branch_id;
        }

        $notice = Notice::query()->create([
            'branch_id' => $validated['branch_id'] ?? null,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'audience_role' => $validated['audience_role'],
            'is_pinned' => (bool) ($validated['is_pinned'] ?? false),
            'published_at' => now(),
            'created_by' => $actor?->id,
        ]);

        $this->audit->log('notice.published', 'notice', (string) $notice->id, ['audience' => $notice->audience_role]);

        return back()->with('status', 'Notice published.');
    }

    private function ensureAssignedBranch($actor): void
    {
        if ($actor && $actor->hasRole('branch_manager') && $actor->branch_id === null) {
            abort(403, 'Branch manager must be assigned to a branch.');
        }
    }
}
