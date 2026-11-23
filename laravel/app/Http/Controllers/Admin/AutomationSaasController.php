<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AutomationAiAgent;
use App\Models\AutomationWorkflow;
use App\Models\SaasTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AutomationSaasController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        $tenants = SaasTenant::query()
            ->when(!$actor->hasRole('admin'), fn ($query) => $query->where('owner_user_id', $actor->id))
            ->with(['branding', 'owner'])
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
