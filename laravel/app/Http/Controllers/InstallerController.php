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

        return redirect()->route('login')->with('status', 'AEC LMS installed cleanly on a fresh database. Sign in with your new administrator account.');
    }
}
