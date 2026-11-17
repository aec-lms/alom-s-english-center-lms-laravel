<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Installer\FreshInstallerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InstallerController extends Controller
{
    public function __construct(private readonly FreshInstallerService $installer) {}

    public function index(): View
    {
        $requirements = $this->installer->checkServerRequirements();
        return view('installer.wizard', compact('requirements'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_name' => ['required', 'string', 'max:120'],
            'app_url' => ['required', 'url', 'max:255'],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'locale' => ['required', 'string', 'max:35', 'regex:/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/'],
            'timezone' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:180'],
            'admin_password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        try {
            $pdo = DB::connection()->getPdo();
            $this->installer->assertFreshEmptyDatabase(
                $pdo,
                (string) DB::connection()->getDatabaseName(),
                DB::connection()->getDriverName()
            );

            $this->installer->installFresh($validated);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['installer' => $e->getMessage()]);
        }

        return redirect()->route('login')->with('status', 'LMS installed successfully. Sign in with your new administrator account.');
    }
}
