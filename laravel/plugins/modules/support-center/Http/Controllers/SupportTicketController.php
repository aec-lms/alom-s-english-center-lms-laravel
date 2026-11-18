<?php

declare(strict_types=1);

namespace Plugins\\Modules\\SupportCenter\\Http\\Controllers;

use App\\Core\\Plugins\\PluginManager;
use App\\Core\\Security\\AuditLogger;
use App\\Http\\Controllers\\Controller;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;
use Illuminate\\View\\View;
use Plugins\\Modules\\SupportCenter\\Models\\SupportTicket;
use Plugins\\Modules\\SupportCenter\\Models\\SupportTicketMessage;

class SupportTicketController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function studentIndex(Request $request): View
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $tickets = SupportTicket::query()
            ->where('user_id', $student->id)
            ->with('latestMessage')
            ->latest('last_message_at')
            ->paginate(15);

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

        $ticket = DB::transaction(function () use ($student, $data): SupportTicket {
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

        $this->audit->log('support.ticket_created', 'support_ticket', (string) $ticket->id, [], $student->id);

        return redirect()->route('plugins.support.student.show', $ticket->id)->with('status', 'আপনার সাপোর্ট অনুরোধ জমা হয়েছে।');
    }

    public function studentShow(Request $request, int $ticket): View
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $record = SupportTicket::query()->where('user_id', $student->id)->findOrFail($ticket);
        $record->messages()->where('sender_role', 'staff')->whereNull('read_at')->update(['read_at' => now()]);
        $record->load('messages.sender:id,name');

        return view('support-center::student-show', ['ticket' => $record]);
    }

    public function studentReply(Request $request, int $ticket): RedirectResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $data = $request->validate(['message' => ['required', 'string', 'min:2', 'max:5000']]);

        DB::transaction(function () use ($ticket, $student, $data): void {
            $record = SupportTicket::query()->where('user_id', $student->id)->lockForUpdate()->findOrFail($ticket);
            abort_if($record->status === 'closed', 409, 'এই টিকিট বন্ধ করা হয়েছে।');
            $record->messages()->create([
                'sender_id' => $student->id,
                'sender_role' => 'student',
                'body' => trim($data['message']),
            ]);
            $record->update(['last_message_at' => now()]);
        });

        return back()->with('status', 'আপনার উত্তর পাঠানো হয়েছে।');
    }

    public function adminIndex(Request $request): View
    {
        $actor = $request->user();
        $this->authorizeSupportStaff($actor);
        $tickets = SupportTicket::query()
            ->with(['user:id,name,email', 'latestMessage'])
            ->when($actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', (int) $actor->branch_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->latest('last_message_at')
            ->paginate(20)
            ->withQueryString();

        return view('support-center::admin-index', compact('tickets'));
    }

    public function adminShow(Request $request, int $ticket): View
    {
        $actor = $request->user();
        $this->authorizeSupportStaff($actor);
        $record = $this->staffTicketQuery($actor)->with(['user:id,name,email', 'messages.sender:id,name'])->findOrFail($ticket);
        $record->messages()->where('sender_role', 'student')->whereNull('read_at')->update(['read_at' => now()]);
        $record->load('messages.sender:id,name');

        return view('support-center::admin-show', ['ticket' => $record]);
    }

    public function adminReply(Request $request, int $ticket): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeSupportStaff($actor);
        $data = $request->validate(['message' => ['required', 'string', 'min:2', 'max:5000']]);

        DB::transaction(function () use ($ticket, $actor, $data): void {
            $record = $this->staffTicketQuery($actor)->lockForUpdate()->findOrFail($ticket);
            abort_if($record->status === 'closed', 409, 'বন্ধ টিকিটে উত্তর দেওয়া যাবে না।');
            $record->messages()->create([
                'sender_id' => $actor->id,
                'sender_role' => 'staff',
                'body' => trim($data['message']),
            ]);
            $record->update(['status' => 'in_progress', 'last_message_at' => now()]);
        });

        $this->audit->log('support.ticket_replied', 'support_ticket', (string) $ticket, [], $actor->id);

        return back()->with('status', 'উত্তর পাঠানো হয়েছে।');
    }

    public function adminUpdateStatus(Request $request, int $ticket): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeSupportStaff($actor);
        $data = $request->validate(['status' => ['required', 'in:open,in_progress,closed']]);

        DB::transaction(function () use ($ticket, $actor, $data): void {
            $record = $this->staffTicketQuery($actor)->lockForUpdate()->findOrFail($ticket);
            $record->update([
                'status' => $data['status'],
                'closed_at' => $data['status'] === 'closed' ? now() : null,
            ]);
        });

        $this->audit->log('support.ticket_status_changed', 'support_ticket', (string) $ticket, ['status' => $data['status']], $actor->id);

        return back()->with('status', 'টিকিটের অবস্থা পরিবর্তন হয়েছে।');
    }

    private function authorizeSupportStaff($actor): void
    {
        abort_unless($actor && (
            $actor->hasRole('admin')
            || ($actor->hasRole('branch_manager') && $actor->hasPermission('support.tickets.manage'))
        ), 403);

        if ($actor->hasRole('branch_manager')) {
            abort_unless(
                (int) ($actor->branch_id ?? 0) > 0
                && app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists(),
                403,
                'Branch manager must be assigned to an active branch.'
            );
        }
    }

    private function staffTicketQuery($actor)
    {
        return SupportTicket::query()->when(
            $actor->hasRole('branch_manager'),
            fn ($q) => $q->where('branch_id', (int) $actor->branch_id)
        );
    }
}
