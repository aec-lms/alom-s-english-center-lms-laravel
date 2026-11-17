<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RolePermissionController extends Controller
{
    public function index(): View
    {
        $roles = Role::query()->with('permissions')->orderBy('name')->get();
        $permissions = Permission::query()->orderBy('module')->orderBy('name')->get()->groupBy('module');

        return view('admin.roles.index', compact('roles', 'permissions'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]*$/', 'unique:roles,slug'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $role = Role::query()->create([
            'name' => trim($validated['name']),
            'slug' => strtolower($validated['slug']),
            'description' => $validated['description'] ?? null,
            'is_system' => false,
        ]);

        $this->audit('admin.role.created', $role, ['slug' => $role->slug]);

        return redirect()->route('admin.roles.index')->with('status', 'Custom role created. Assign permissions below.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->slug === 'admin', 403, 'The protected Administrator role cannot be modified.');

        $validated = $request->validate([
            'permissions' => ['present', 'array', 'max:500'],
            'permissions.*' => ['required', 'integer', 'distinct', 'exists:permissions,id'],
        ]);

        $permissionIds = array_map('intval', $validated['permissions']);
        DB::transaction(function () use ($role, $permissionIds): void {
            $role->permissions()->sync($permissionIds);
            $this->audit('admin.role.permissions.updated', $role, [
                'permission_ids' => $permissionIds,
                'permission_count' => count($permissionIds),
            ]);
        });

        return redirect()->route('admin.roles.index')->with('status', "Permissions updated for {$role->name}.");
    }

    private function audit(string $action, Role $role, array $metadata): void
    {
        AuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => 'role',
            'entity_id' => (string) $role->id,
            'ip_address' => request()->ip(),
            'metadata' => $metadata,
        ]);
    }
}
