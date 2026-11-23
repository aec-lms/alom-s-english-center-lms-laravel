<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AutomationAiAgent;
use App\Models\AutomationContact;
use App\Models\AutomationCampaign;
use App\Models\AutomationConversation;
use Illuminate\Support\Facades\DB;
use App\Models\AutomationWorkflow;
use App\Models\SaasTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Services\Automation\WorkflowDefinitionValidator;
use InvalidArgumentException;

class AutomationSaasController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        $tenants = SaasTenant::query()
            ->when(!$actor->hasRole('admin'), fn ($query) => $query->where('owner_user_id', $actor->id))
            ->with(['branding', 'owner', 'workflows', 'conversations.contact', 'conversations.messages'])
            ->withCount(['agents', 'channels', 'contacts', 'conversations', 'campaigns', 'workflows'])
            ->latest()
            ->paginate(20);

        return view('admin.automation-saas.index', [
            'tenants' => $tenants,
            'isPlatformAdmin' => $actor->hasRole('admin'),
        ]);
    }

    public function storeTenant(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:100', 'alpha_dash', 'unique:saas_tenants,slug'],
            'plan_key' => ['required', Rule::in(['starter', 'growth', 'business', 'enterprise'])],
        ]);

        $tenant = SaasTenant::create([
            'owner_user_id' => $request->user()->id,
            'name' => $data['name'],
            'slug' => $data['slug'] ?: Str::slug($data['name']).'-'.Str::lower(Str::random(5)),
            'plan_key' => $data['plan_key'],
            'status' => 'trial',
        ]);

        $tenant->branding()->create(['brand_name' => $tenant->name]);

        return redirect()->route('admin.automation-saas.index')->with('success', 'Workspace created. Configure your branding and integrations next.');
    }

    public function storeAgent(Request $request, SaasTenant $tenant): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin') || $tenant->owner_user_id === $request->user()->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'system_prompt' => ['nullable', 'string', 'max:12000'],
        ]);

        $baseSlug = Str::slug($data['name']) ?: 'assistant';
        $slug = $baseSlug;
        $suffix = 2;
        while ($tenant->agents()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        $tenant->agents()->create([
            'name' => $data['name'],
            'slug' => $slug,
            'system_prompt' => $data['system_prompt'] ?? null,
            'status' => 'draft',
            'configuration' => ['provider' => 'not_configured'],
        ]);

        return redirect()->route('admin.automation-saas.index')->with('success', 'AI agent draft created. Add an AI provider key to activate it.');
    }

    public function storeContact(Request $request, SaasTenant $tenant): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin') || (int) $tenant->owner_user_id === (int) $request->user()->id, 404);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email:rfc', 'max:190', Rule::unique('automation_contacts', 'email')->where('tenant_id', $tenant->id)],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[+0-9() .-]+$/'],
            'source' => ['required', Rule::in(['manual', 'website', 'import', 'api'])],
        ]);
        if (empty($data['email']) && empty($data['phone'])) {
            return back()->withErrors(['email' => 'Provide an email address or phone number.'])->withInput();
        }
        $tenant->contacts()->create($data);
        return back()->with('success', 'CRM contact added to this workspace.');
    }

    public function storeCampaign(Request $request, SaasTenant $tenant): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin') || (int) $tenant->owner_user_id === (int) $request->user()->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'channel' => ['required', Rule::in(['whatsapp', 'messenger', 'instagram', 'telegram', 'email', 'sms'])],
            'message_template' => ['required', 'string', 'max:10000'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);
        $tenant->campaigns()->create($data + [
            'status' => 'draft',
            'audience_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
        ]);
        return back()->with('success', 'Campaign draft saved. No messages are sent until a verified provider and consent-based audience are configured.');
    }

    private function authorizeTenant(Request $request, SaasTenant $tenant): void
    {
        abort_unless($request->user()->hasRole('admin') || (int) $tenant->owner_user_id === (int) $request->user()->id, 404);
        abort_unless($tenant->status === 'active' || $tenant->status === 'trial', 403, 'Workspace is not active.');
    }

    public function storeChannel(Request $request, SaasTenant $tenant): RedirectResponse
    {
        $this->authorizeTenant($request, $tenant);
        $data = $request->validate([
            'provider' => ['required', Rule::in(['whatsapp', 'messenger', 'instagram', 'telegram', 'website_chat', 'email', 'sms'])],
            'name' => ['required', 'string', 'max:120'],
            'access_token' => ['nullable', 'string', 'max:8192'],
            'configuration' => ['nullable', 'array'],
        ]);
        $tenant->channels()->create([
            'provider' => $data['provider'],
            'name' => $data['name'],
            'status' => 'disconnected',
            'credentials_encrypted' => $data['access_token'] ?? null,
            'configuration' => $data['configuration'] ?? [],
        ]);
        return back()->with('success', 'Channel saved securely. It remains disconnected until provider verification succeeds.');
    }

    public function assignConversation(Request $request, SaasTenant $tenant, AutomationConversation $conversation): RedirectResponse
    {
        $this->authorizeTenant($request, $tenant);
        abort_unless((int) $conversation->tenant_id === (int) $tenant->id, 404);
        $data = $request->validate(['assigned_user_id' => ['nullable', 'integer', 'exists:users,id']]);
        $conversation->update(['assigned_user_id' => $data['assigned_user_id'] ?? null]);
        return back()->with('success', 'Conversation assignment updated.');
    }

    public function updateConversation(Request $request, SaasTenant $tenant, AutomationConversation $conversation): RedirectResponse
    {
        $this->authorizeTenant($request, $tenant);
        abort_unless((int) $conversation->tenant_id === (int) $tenant->id, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'pending', 'resolved', 'closed'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);
        $conversation->update($data);
        return back()->with('success', 'Conversation status and priority updated.');
    }

    public function replyConversation(Request $request, SaasTenant $tenant, AutomationConversation $conversation): RedirectResponse
    {
        $this->authorizeTenant($request, $tenant);
        abort_unless((int) $conversation->tenant_id === (int) $tenant->id, 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        DB::transaction(function () use ($conversation, $request, $data): void {
            $conversation->messages()->create([
                'sender_user_id' => $request->user()->id,
                'direction' => 'outbound',
                'message_type' => 'text',
                'body' => $data['body'],
                'sent_at' => now(),
            ]);
            $conversation->update(['last_message_at' => now(), 'status' => 'open']);
        });
        return back()->with('success', 'Reply saved to the shared inbox. Provider delivery requires a verified channel adapter.');
    }

    public function storeWebhook(Request $request, SaasTenant $tenant): RedirectResponse
    {
        $this->authorizeTenant($request, $tenant);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'event_name' => ['required', Rule::in(['contact.created', 'conversation.opened', 'message.received', 'campaign.completed', 'workflow.completed'])],
            'target_url' => ['required', 'url', 'max:2048', 'starts_with:https://'],
        ]);
        $host = strtolower((string) parse_url($data['target_url'], PHP_URL_HOST));
        if ($host === '' || in_array($host, ['localhost', 'localhost.localdomain'], true) || filter_var($host, FILTER_VALIDATE_IP) && (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            return back()->withErrors(['target_url' => 'Webhook destination must use a public HTTPS host.'])->withInput();
        }
        $secret = Str::random(48);
        $tenant->webhooks()->create($data + ['secret_encrypted' => $secret, 'is_active' => true]);
        return back()->with('webhook_secret', $secret)->with('success', 'Webhook registered. Copy the signing secret now; it is stored encrypted.');
    }

    public function publishWorkflow(Request $request, SaasTenant $tenant, AutomationWorkflow $workflow): RedirectResponse
    {
        abort_unless((int) $workflow->tenant_id === (int) $tenant->id, 404);
        abort_unless($request->user()->hasRole('admin') || (int) $tenant->owner_user_id === (int) $request->user()->id, 404);

        $data = $request->validate(['definition' => ['required', 'string', 'max:50000']]);
        try {
            $definition = json_decode($data['definition'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($definition)) throw new InvalidArgumentException('Workflow definition must be a JSON object.');
            $definition = WorkflowDefinitionValidator::validate($definition, $workflow->trigger_event);
        } catch (\JsonException | InvalidArgumentException $exception) {
            return back()->withErrors(['definition' => $exception->getMessage()])->withInput();
        }

        $workflow->forceFill([
            'definition' => $definition,
            'status' => 'published',
            'version' => max(1, (int) $workflow->version + 1),
        ])->save();

        return redirect()->route('admin.automation-saas.index')->with('success', 'Workflow validated and published. Its graph is acyclic and all nodes are supported.');
    }

    public function storeWorkflow(Request $request, SaasTenant $tenant): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin') || $tenant->owner_user_id === $request->user()->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'trigger_event' => ['required', Rule::in(['contact.created', 'conversation.opened', 'message.received', 'form.submitted', 'order.created'])],
        ]);

        $tenant->workflows()->create([
            'name' => $data['name'],
            'trigger_event' => $data['trigger_event'],
            'definition' => ['version' => 1, 'nodes' => [], 'edges' => []],
            'status' => 'draft',
        ]);

        return redirect()->route('admin.automation-saas.index')->with('success', 'Workflow draft created. Add nodes and connect integrations before publishing.');
    }
}
