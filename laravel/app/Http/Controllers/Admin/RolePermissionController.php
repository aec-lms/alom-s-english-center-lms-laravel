<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RolePermissionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

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

        $role = DB::transaction(function () use ($validated, $request): Role {
            $role = Role::query()->create([
                'name' => trim($validated['name']),
                'slug' => strtolower($validated['slug']),
                'description' => $validated['description'] ?? null,
                'is_system' => false,
            ]);

            $this->audit->logRequired(
                'admin.role.created',
                'role',
                (string) $role->id,
                ['slug' => $role->slug],
                (int) $request->user()->getAuthIdentifier(),
            );

            return $role;
        });

        return redirect()->route('admin.roles.index')->with('status', 'Custom role created. Assign permissions below.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $actor = $request->user();
        abort_if(
            $role->slug === 'admin',
            403,
            'The protected Administrator role cannot be modified.'
        );
        abort_if(
            $role->is_system && !$actor?->hasRole('admin'),
            403,
            'Only an existing Administrator may modify system roles.'
        );

        $validated = $request->validate([
            'permissions' => ['sometimes', 'array', 'max:500'],
            'permissions.*' => ['required', 'integer', 'distinct', 'exists:permissions,id'],
        ]);

        $permissionIds = array_map('intval', $validated['permissions'] ?? []);
        if (!$actor?->hasRole('admin') && Permission::query()
            ->whereIn('id', $permissionIds)
            ->where('module', 'core')
            ->where('slug', '!=', 'core.dashboard.view')
            ->exists()) {
            abort(403, 'Only an existing Administrator may grant protected Core permissions.');
        }
        DB::transaction(function () use ($role, $permissionIds, $actor): void {
            $role->permissions()->sync($permissionIds);
            $this->audit->logRequired(
                'admin.role.permissions.updated',
                'role',
                (string) $role->id,
                [
                    'permission_ids' => $permissionIds,
                    'permission_count' => count($permissionIds),
                ],
                (int) $actor->getAuthIdentifier(),
            );
        });

        return redirect()->route('admin.roles.index')->with('status', "Permissions updated for {$role->name}.");
    }

}
