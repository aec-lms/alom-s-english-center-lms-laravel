<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Backup\BackupService;
use App\Http\Controllers\Controller;
use App\Models\BackupRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    public function index(): View
    {
        $records = BackupRecord::query()->latest()->get();
        return view('admin.backups.index', compact('records'));
    }

    public function store(Request $request): RedirectResponse
    {
        $includeFiles = $request->boolean('include_files', true);
        $record = $this->backups->createEncryptedBackup(auth()->id(), $includeFiles);

        return back()->with('status', "Encrypted backup created: {$record->filename}");
    }

    public function restore(Request $request, BackupRecord $backup): RedirectResponse
    {
        $request->validate(['confirm_restore' => ['required', 'accepted']]);
        try {
            $this->backups->restoreEncryptedBackup($backup, auth()->id());
            return back()->with('status', "Backup {$backup->filename} verified and restored.");
        } catch (\Throwable $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }
    }
}
