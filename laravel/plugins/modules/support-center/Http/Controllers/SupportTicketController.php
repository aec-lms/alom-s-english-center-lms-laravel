<?php

declare(strict_types=1);

namespace Plugins\Modules\SupportCenter\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\SupportCenter\Models\SupportTicket;
use Plugins\Modules\SupportCenter\Models\SupportTicketMessage;

class SupportTicketController extends Controller
{
    public function studentIndex(Request $request): View
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $tickets = SupportTicket::query()->where('user_id', $student->id)
            ->with('latestMessage')->latest('last_message_at')->paginate(15);
        return view('support-center::student-index', compact('tickets'));
    }

    public function studentStore(Request $request): RedirectResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:160'],
            'message' => ['required', 'string', 'min:5', 'max:5000'],
        ]);
        $ticket = $this->createTicket($student, $data);

        return redirect()->route('plugins.support.student.show', $ticket->id)->with('status', 'Your support request has been submitted.');
    }

    public function studentShow(Request $request, int $ticket): View
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $record = SupportTicket::query()->where('user_id', $student->id)->with('messages.sender')->findOrFail($ticket);
        $record->messages()->where('sender_role', 'admin')->whereNull('read_at')->update(['read_at' => now()]);
        return view('support-center::student-show', ['ticket' => $record->fresh(['messages.sender'])]);
    }

    public function studentReply(Request $request, int $ticket): RedirectResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $record = SupportTicket::query()->where('user_id', $student->id)->findOrFail($ticket);
        $this->addMessage($record, $student->id, 'student', $request);
        return back()->with('status', 'Your reply has been sent.');
    }

    public function adminIndex(Request $request): View
    {
        $actor = $request->user();
        $this->ensureActiveBranch($actor);
        $tickets = SupportTicket::query()
            ->when($actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id ?: -1))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with(['user:id,name,email', 'latestMessage'])
            ->latest('last_message_at')->paginate(20)->withQueryString();
        return view('support-center::admin-index', compact('tickets'));
    }

    public function adminShow(Request $request, int $ticket): View
    {
        $record = $this->adminScopedTicket($request->user(), $ticket);
        $record->messages()->where('sender_role', 'student')->whereNull('read_at')->update(['read_at' => now()]);
        return view('support-center::admin-show', ['ticket' => $record->fresh(['user', 'messages.sender'])]);
    }

    public function adminReply(Request $request, int $ticket): RedirectResponse
    {
        $record = $this->adminScopedTicket($request->user(), $ticket);
        $this->addMessage($record, $request->user()->id, 'admin', $request);
        $record->update(['status' => 'in_progress']);
        return back()->with('status', 'Your reply has been sent.');
    }

    public function adminUpdateStatus(Request $request, int $ticket): RedirectResponse
    {
        $record = $this->adminScopedTicket($request->user(), $ticket);
        $data = $request->validate(['status' => ['required', 'in:open,in_progress,closed']]);
        $record->update([
            'status' => $data['status'],
            'closed_at' => $data['status'] === 'closed' ? now() : null,
        ]);
        return back()->with('status', 'Support ticket status updated.');
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $tickets = SupportTicket::query()->where('user_id', $student->id)
            ->with(['latestMessage'])->latest('last_message_at')->paginate(15);
        return response()->json([
            'data' => $tickets->getCollection()->map(fn (SupportTicket $ticket): array => $this->ticketData($ticket)),
            'meta' => ['current_page' => $tickets->currentPage(), 'last_page' => $tickets->lastPage(), 'per_page' => $tickets->perPage(), 'total' => $tickets->total()],
        ]);
    }

    public function apiStore(Request $request): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:160'],
            'message' => ['required', 'string', 'min:5', 'max:5000'],
        ]);
        $ticket = $this->createTicket($student, $data);
        return response()->json(['message' => 'Your support request has been submitted.', 'data' => $this->ticketData($ticket->load('latestMessage'))], 201);
    }

    public function apiShow(Request $request, int $ticket): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $record = SupportTicket::query()->where('user_id', $student->id)->with('messages.sender')->findOrFail($ticket);
        $record->messages()->where('sender_role', 'admin')->whereNull('read_at')->update(['read_at' => now()]);
        $record->load('messages.sender');
        return response()->json([
            'data' => [
                ...$this->ticketData($record),
                'messages' => $record->messages->map(fn (SupportTicketMessage $message): array => [
                    'id' => $message->id,
                    'sender_name' => $message->sender?->name ?? 'Academy support',
                    'sender_role' => $message->sender_role,
                    'body' => $message->body,
                    'created_at' => $message->created_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    public function apiReply(Request $request, int $ticket): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $record = SupportTicket::query()->where('user_id', $student->id)->findOrFail($ticket);
        $message = $this->addMessage($record, $student->id, 'student', $request);
        return response()->json([
            'message' => 'Your reply has been sent.',
            'data' => ['id' => $message->id, 'sender_name' => $student->name, 'sender_role' => 'student', 'body' => $message->body, 'created_at' => $message->created_at?->toIso8601String()],
        ], 201);
    }

    private function createTicket($student, array $data): SupportTicket
    {
        return DB::transaction(function () use ($student, $data): SupportTicket {
            $ticket = SupportTicket::query()->create([
                'user_id' => $student->id,
                'branch_id' => $student->branch_id,
                'subject' => trim($data['subject']),
                'status' => 'open',
                'last_message_at' => now(),
            ]);
            $ticket->messages()->create([
                'sender_id' => $student->id,
                'sender_role' => 'student',
                'body' => trim($data['message']),
            ]);
            return $ticket;
        });
    }

    private function addMessage(SupportTicket $ticket, int $senderId, string $senderRole, Request $request): SupportTicketMessage
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:2', 'max:5000']]);
        return DB::transaction(function () use ($ticket, $senderId, $senderRole, $data): SupportTicketMessage {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            abort_if($locked->status === 'closed', 409, 'This support ticket is closed.');
            $message = $locked->messages()->create([
                'sender_id' => $senderId,
                'sender_role' => $senderRole,
                'body' => trim($data['message']),
            ]);
            $locked->update(['last_message_at' => now()]);
            return $message;
        });
    }

    private function adminScopedTicket($actor, int $ticket): SupportTicket
    {
        $this->ensureActiveBranch($actor);
        return SupportTicket::query()
            ->when($actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id ?: -1))
            ->findOrFail($ticket);
    }

    private function ensureActiveBranch($actor): void
    {
        if ($actor && $actor->hasRole('branch_manager')) {
            abort_unless((int) ($actor->branch_id ?? 0) > 0
                && app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists(),
                403,
                'Branch manager must be assigned to an active branch.'
            );
        }
    }

    private function ticketData(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'last_message' => $ticket->latestMessage?->body,
            'last_message_at' => $ticket->last_message_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }
}
