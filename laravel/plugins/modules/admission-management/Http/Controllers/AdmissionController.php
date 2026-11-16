<?php

declare(strict_types=1);

namespace Plugins\Modules\AdmissionManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\AdmissionManagement\Models\AdmissionApplication;

class AdmissionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $applications = Schema::hasTable('admission_applications')
            ? AdmissionApplication::query()
                ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
                ->orderByDesc('id')
                ->get()
            : collect();

        return view('admission-management::index', compact('applications'));
    }

    public function applyPublic(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'applicant_name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
            'guardian_phone' => ['nullable', 'string', 'max:40'],
            'desired_course' => ['required', 'string', 'max:160'],
            'preferred_batch' => ['nullable', 'string', 'max:80'],
            'previous_education' => ['nullable', 'string', 'max:160'],
            'branch_id' => $this->branchRule(),
        ]);

        if (!$this->branchIntegrationAvailable()) { $validated['branch_id'] = null; }

        $app = AdmissionApplication::query()->create($validated + [
            'application_code' => 'ADM-' . strtoupper(Str::random(7)),
            'status' => 'pending',
        ]);

        return back()->with('status', "Admission application submitted! Tracking ID: {$app->application_code}");
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $validated = $request->validate([
            'applicant_name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
            'guardian_phone' => ['nullable', 'string', 'max:40'],
            'desired_course' => ['required', 'string', 'max:160'],
            'preferred_batch' => ['nullable', 'string', 'max:80'],
            'previous_education' => ['nullable', 'string', 'max:160'],
            'branch_id' => $this->branchRule(),
        ]);

        if ($actor && $actor->hasRole('branch_manager')) {
            $validated['branch_id'] = $actor->branch_id;
        }

        if (!$this->branchIntegrationAvailable()) { $validated['branch_id'] = null; }

        $app = AdmissionApplication::query()->create($validated + [
            'application_code' => 'ADM-' . strtoupper(Str::random(7)),
            'status' => 'pending',
        ]);

        $this->audit->log('admission.created', 'admission_application', (string) $app->id);

        return back()->with('status', "Admission application '{$app->application_code}' logged.");
    }

    public function review(Request $request, AdmissionApplication $application): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null && (int) $application->branch_id !== (int) $actor->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        $validated = $request->validate([
            'status' => ['required', 'in:pending,approved,rejected'],
            'reviewer_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $application->update([
            'status' => $validated['status'],
            'reviewer_notes' => $validated['reviewer_notes'] ?? null,
            'reviewed_by' => $actor?->id,
            'reviewed_at' => now(),
        ]);

        $this->audit->log('admission.reviewed', 'admission_application', (string) $application->id, [
            'status' => $validated['status'],
        ]);

        return back()->with('status', "Application {$application->application_code} marked as {$validated['status']}.");
    }

    private function branchIntegrationAvailable(): bool
    {
        return app(PluginManager::class)->isEnabled('branch-management') && Schema::hasTable('branches');
    }

    private function branchRule(): array
    {
        return $this->branchIntegrationAvailable()
            ? ['nullable', 'integer', 'exists:branches,id']
            : ['nullable', 'integer'];
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }
        if ($actor->branch_id === null || !$this->branchIntegrationAvailable()) {
            abort(403, 'Branch manager must be assigned to a branch.');
        }
        $activeBranch = app('db')->table('branches')
            ->where('id', $actor->branch_id)
            ->where('is_active', true)
            ->exists();
        if (!$activeBranch) {
            abort(403, 'Branch manager access is disabled because the assigned branch is inactive.');
        }
    }
}
