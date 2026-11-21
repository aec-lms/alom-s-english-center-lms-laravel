<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserRoleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $users = User::query()->with('roles')->latest()->paginate(25);
        $roles = Role::query()->with('permissions')->get();

        return view('admin.users.index', compact('users', 'roles'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
            'role_id' => ['required', 'exists:roles,id'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $targetRole = Role::query()->findOrFail((int) $validated['role_id']);
        abort_if(
            $targetRole->slug === 'admin' && !$request->user()?->hasRole('admin'),
            403,
            'Only an existing Administrator may grant the protected Administrator role.'
        );

        $user = DB::transaction(function () use ($validated, $request): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'password' => Hash::make($validated['password']),
                'branch_id' => $validated['branch_id'] ?? null,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            $user->roles()->sync([(int) $validated['role_id']]);
            $this->audit->logRequired(
                'user.created',
                'user',
                (string) $user->id,
                ['role_id' => $validated['role_id']],
                (int) $request->user()->getAuthIdentifier(),
            );

            return $user;
        });

        return back()->with('status', "User {$user->email} created.");
    }
}
