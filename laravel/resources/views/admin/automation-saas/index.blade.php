@extends('layouts.admin')

@section('title', 'AI Automation SaaS')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">AI Automation SaaS</h1>
            <p class="mt-1 text-sm text-slate-500">White-label workspaces, AI agents, omnichannel inbox, campaigns and workflows.</p>
        </div>
        <span class="rounded-full bg-violet-100 px-3 py-1 text-xs font-bold text-violet-800">Platform module</span>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([['AI workspaces', $tenants->total()], ['AI agents', $tenants->sum('agents_count')], ['Connected channels', $tenants->sum('channels_count')], ['CRM contacts', $tenants->sum('contacts_count')]] as [$label, $value])
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</div><div class="mt-2 text-2xl font-extrabold text-slate-900">{{ number_format($value) }}</div></div>
        @endforeach
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
        <section class="rounded-xl border border-slate-200 bg-white p-5 xl:col-span-1">
            <h2 class="text-base font-bold text-slate-900">Create white-label workspace</h2>
            <p class="mt-1 text-xs text-slate-500">Create a separate customer workspace. Branding and custom domains can be configured independently.</p>
            <form method="POST" action="{{ route('admin.automation-saas.tenants.store') }}" class="mt-4 space-y-3">
                @csrf
                <label class="block text-xs font-semibold text-slate-700">Workspace name<input name="name" required maxlength="160" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Your client brand"></label>
                <label class="block text-xs font-semibold text-slate-700">Workspace slug (optional)<input name="slug" maxlength="100" pattern="[A-Za-z0-9_-]+" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="your-client"></label>
                <label class="block text-xs font-semibold text-slate-700">Plan<select name="plan_key" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="starter">Starter</option><option value="growth">Growth</option><option value="business">Business</option><option value="enterprise">Enterprise</option></select></label>
                <button class="w-full rounded-lg bg-violet-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-violet-800">Create workspace</button>
            </form>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5 xl:col-span-2">
            <h2 class="text-base font-bold text-slate-900">Automation modules</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ([
                    ['AI Agents & Chatbots', 'Agent profiles, prompts and provider configuration', '🤖'],
                    ['Shared Inbox & CRM', 'Contacts, conversations, assignment and message history', '💬'],
                    ['Omnichannel', 'WhatsApp, Messenger, Instagram, Telegram and web chat adapters', '🌐'],
                    ['Broadcasts & Marketing', 'Campaign audiences, schedules and delivery tracking', '📣'],
                    ['Workflow Automation', 'Event triggers, workflow definitions and execution history', '⚙️'],
                    ['Ecommerce Integrations', 'Store connections, order events and customer sync', '🛒'],
                    ['APIs & Webhooks', 'Tenant-scoped API credentials and outbound event hooks', '🔗'],
                    ['White-label & Pricing', 'Custom brand, domain, subscription plans and usage limits', '🏷️'],
                ] as [$title, $description, $icon])
                    <div class="rounded-lg border border-slate-200 p-3"><div class="flex items-center gap-2 text-sm font-bold text-slate-800"><span>{{ $icon }}</span>{{ $title }}</div><p class="mt-1 text-xs leading-5 text-slate-500">{{ $description }}</p></div>
                @endforeach
            </div>
        </section>
    </div>

    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <div class="flex items-center justify-between gap-3"><h2 class="text-base font-bold text-slate-900">Workspaces</h2><span class="text-xs text-slate-500">{{ $tenants->total() }} total</span></div>
        <div class="mt-4 space-y-4">
            @forelse ($tenants as $tenant)
                <div class="rounded-lg border border-slate-200 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div><div class="font-bold text-slate-900">{{ $tenant->branding?->brand_name ?: $tenant->name }}</div><div class="mt-1 text-xs text-slate-500">{{ $tenant->slug }} · {{ strtoupper($tenant->plan_key) }} · {{ ucfirst($tenant->status) }}</div>@if($isPlatformAdmin)<div class="mt-1 text-xs text-slate-500">Owner: {{ $tenant->owner?->email }}</div>@endif</div>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ $tenant->timezone }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs text-slate-600">
                        <span class="rounded bg-slate-100 px-2 py-1">{{ $tenant->agents_count }} agents</span>
                        <span class="rounded bg-slate-100 px-2 py-1">{{ $tenant->channels_count }} channels</span>
                        <span class="rounded bg-slate-100 px-2 py-1">{{ $tenant->contacts_count }} contacts</span>
                        <span class="rounded bg-slate-100 px-2 py-1">{{ $tenant->conversations_count }} conversations</span>
                        <span class="rounded bg-slate-100 px-2 py-1">{{ $tenant->campaigns_count }} campaigns</span>
                        <span class="rounded bg-slate-100 px-2 py-1">{{ $tenant->workflows_count }} workflows</span>
                    </div>
                    @if($tenant->owner_user_id === auth()->id() || $isPlatformAdmin)
                        <div class="mt-4 grid gap-3 lg:grid-cols-2">
                            <form method="POST" action="{{ route('admin.automation-saas.contacts.store', $tenant) }}" class="rounded-lg border border-slate-200 p-3 space-y-2">@csrf
                                <h3 class="text-xs font-bold text-slate-700">Add CRM contact</h3>
                                <input name="name" maxlength="160" aria-label="Contact name" placeholder="Contact name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                <input name="email" type="email" maxlength="190" aria-label="Contact email" placeholder="Email address" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                <input name="phone" maxlength="32" aria-label="Contact phone" placeholder="Phone number" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                <select name="source" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs"><option value="manual">Manual</option><option value="website">Website</option><option value="import">Import</option><option value="api">API</option></select>
                                <button class="rounded-lg bg-slate-800 px-3 py-2 text-xs font-bold text-white">Save contact</button>
                            </form>
                            <form method="POST" action="{{ route('admin.automation-saas.campaigns.store', $tenant) }}" class="rounded-lg border border-slate-200 p-3 space-y-2">@csrf
                                <h3 class="text-xs font-bold text-slate-700">Create campaign draft</h3>
                                <input name="name" required maxlength="160" aria-label="Campaign name" placeholder="Campaign name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                <select name="channel" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs"><option value="whatsapp">WhatsApp</option><option value="messenger">Messenger</option><option value="instagram">Instagram</option><option value="telegram">Telegram</option><option value="email">Email</option><option value="sms">SMS</option></select>
                                <textarea name="message_template" required maxlength="10000" rows="3" aria-label="Campaign message" placeholder="Message template" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs"></textarea>
                                <label class="block text-xs text-slate-500">Schedule (optional)<input type="datetime-local" name="scheduled_at" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-xs"></label>
                                <button class="rounded-lg bg-violet-700 px-3 py-2 text-xs font-bold text-white">Save campaign draft</button>
                            </form>
                        </div>
                        <div class="mt-3 grid gap-3 lg:grid-cols-2">
                            <form method="POST" action="{{ route('admin.automation-saas.agents.store', $tenant) }}" class="flex flex-wrap gap-2">@csrf<input name="name" required maxlength="120" aria-label="AI agent name" placeholder="New AI agent name" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-xs"><button class="rounded-lg border border-violet-300 px-3 py-2 text-xs font-bold text-violet-700">Add agent</button></form>
                            <form method="POST" action="{{ route('admin.automation-saas.workflows.store', $tenant) }}" class="flex flex-wrap gap-2">@csrf<input name="name" required maxlength="160" aria-label="Workflow name" placeholder="New workflow name" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-xs"><select name="trigger_event" aria-label="Workflow trigger" class="rounded-lg border border-slate-300 px-2 py-2 text-xs"><option value="contact.created">Contact created</option><option value="conversation.opened">Conversation opened</option><option value="message.received">Message received</option><option value="form.submitted">Form submitted</option><option value="order.created">Order created</option></select><button class="rounded-lg border border-emerald-300 px-3 py-2 text-xs font-bold text-emerald-700">Add workflow</button></form>
                        </div>
                        <div class="mt-4 grid gap-3 lg:grid-cols-2">
                            <form method="POST" action="{{ route('admin.automation-saas.channels.store', $tenant) }}" class="rounded-lg border border-slate-200 p-3 space-y-2">@csrf
                                <h3 class="text-xs font-bold text-slate-700">Connect a channel</h3>
                                <input name="name" required maxlength="120" placeholder="Channel display name" aria-label="Channel display name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                <select name="provider" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs"><option value="whatsapp">WhatsApp</option><option value="messenger">Messenger</option><option value="instagram">Instagram</option><option value="telegram">Telegram</option><option value="website_chat">Website Chat</option><option value="email">Email</option><option value="sms">SMS</option></select>
                                <input name="access_token" type="password" autocomplete="new-password" maxlength="8192" placeholder="Provider access token (optional)" aria-label="Provider access token" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                <button class="rounded-lg bg-slate-800 px-3 py-2 text-xs font-bold text-white">Save encrypted channel</button>
                                <p class="text-xs text-slate-500">Saved disconnected until a provider handshake confirms it.</p>
                            </form>
                            <form method="POST" action="{{ route('admin.automation-saas.webhooks.store', $tenant) }}" class="rounded-lg border border-slate-200 p-3 space-y-2">@csrf
                                <h3 class="text-xs font-bold text-slate-700">Register outbound webhook</h3>
                                <input name="name" required maxlength="120" placeholder="Webhook name" aria-label="Webhook name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                <select name="event_name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs"><option value="contact.created">Contact created</option><option value="conversation.opened">Conversation opened</option><option value="message.received">Message received</option><option value="campaign.completed">Campaign completed</option><option value="workflow.completed">Workflow completed</option></select>
                                <input name="target_url" type="url" required placeholder="https://your-domain.com/webhook" aria-label="Webhook HTTPS URL" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                <button class="rounded-lg bg-slate-800 px-3 py-2 text-xs font-bold text-white">Register webhook</button>
                                <p class="text-xs text-slate-500">HTTPS only. Signing secret is shown once after creation.</p>
                            </form>
                        </div>
                        @if(session('webhook_secret'))
                            <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900"><strong>Copy this webhook signing secret now:</strong><code class="mt-1 block break-all">{{ session('webhook_secret') }}</code></div>
                        @endif
                        @if($tenant->conversations->isNotEmpty())
                            <div class="mt-4 space-y-3">
                                <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Shared Inbox</h3>
                                @foreach($tenant->conversations->sortByDesc('last_message_at')->take(10) as $conversation)
                                    <div class="rounded-lg border border-slate-200 p-3">
                                        <div class="flex flex-wrap items-center justify-between gap-2"><strong class="text-sm">{{ $conversation->contact?->name ?: $conversation->contact?->email ?: 'Unknown contact' }}</strong><span class="text-xs text-slate-500">{{ ucfirst($conversation->status) }} · {{ ucfirst($conversation->priority) }}</span></div>
                                        <div class="mt-2 max-h-32 space-y-1 overflow-y-auto">@foreach($conversation->messages->sortBy('created_at')->take(20) as $message)<div class="text-xs {{ $message->direction === 'outbound' ? 'text-violet-700' : 'text-slate-600' }}"><span class="font-semibold">{{ $message->direction === 'outbound' ? 'Team' : 'Contact' }}:</span> {{ $message->body }}</div>@endforeach</div>
                                        <div class="mt-3 grid gap-2 md:grid-cols-2">
                                            <form method="POST" action="{{ route('admin.automation-saas.conversations.replies.store', [$tenant, $conversation]) }}" class="flex gap-2">@csrf<input name="body" required maxlength="10000" placeholder="Write a reply..." aria-label="Conversation reply" class="min-w-0 flex-1 rounded border border-slate-300 px-2 py-1.5 text-xs"><button class="rounded bg-violet-700 px-3 py-1.5 text-xs font-bold text-white">Save reply</button></form>
                                            <form method="POST" action="{{ route('admin.automation-saas.conversations.update', [$tenant, $conversation]) }}" class="flex gap-2">@csrf @method('PATCH')<select name="status" class="min-w-0 flex-1 rounded border border-slate-300 px-2 py-1.5 text-xs"><option value="open" @selected($conversation->status === 'open')>Open</option><option value="pending" @selected($conversation->status === 'pending')>Pending</option><option value="resolved" @selected($conversation->status === 'resolved')>Resolved</option><option value="closed" @selected($conversation->status === 'closed')>Closed</option></select><select name="priority" class="min-w-0 flex-1 rounded border border-slate-300 px-2 py-1.5 text-xs"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select><button class="rounded border px-2 py-1.5 text-xs">Update</button></form>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @if($tenant->workflows->isNotEmpty())
                            <div class="mt-4 space-y-3">
                                <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Workflow builder · JSON graph editor</h3>
                                @foreach($tenant->workflows as $workflow)
                                    <form method="POST" action="{{ route('admin.automation-saas.workflows.publish', [$tenant, $workflow]) }}" class="rounded-lg border border-slate-200 p-3">
                                        @csrf
                                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                            <span class="text-sm font-semibold text-slate-800">{{ $workflow->name }} · v{{ $workflow->version }}</span>
                                            <span class="rounded-full bg-slate-100 px-2 py-1 text-xs">{{ ucfirst($workflow->status) }}</span>
                                        </div>
                                        <textarea name="definition" required rows="8" spellcheck="false" aria-label="Workflow graph JSON" class="w-full rounded-lg border border-slate-300 p-3 font-mono text-xs">{{ json_encode($workflow->definition ?: ['version' => 1, 'nodes' => [['id' => 'trigger', 'type' => 'trigger', 'config' => ['event' => $workflow->trigger_event]], ['id' => 'action', 'type' => 'action', 'config' => ['action' => 'notify_admin']]], 'edges' => [['source' => 'trigger', 'target' => 'action']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
                                        <p class="mt-1 text-xs text-slate-500">Supported nodes: trigger, condition, action, delay. Cycles, unknown actions and invalid connections are rejected.</p>
                                        <button class="mt-2 rounded-lg bg-emerald-700 px-3 py-2 text-xs font-bold text-white">Validate &amp; publish workflow</button>
                                    </form>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-slate-300 p-8 text-center"><div class="font-semibold text-slate-700">No workspaces yet</div><p class="mt-1 text-sm text-slate-500">Create your first workspace to begin setting up your branded automation platform.</p></div>
            @endforelse
        </div>
        <div class="mt-4">{{ $tenants->links() }}</div>
    </section>
</div>
@endsection
