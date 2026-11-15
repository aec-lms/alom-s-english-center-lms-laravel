<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Updates\UniversalUpdateService;
use App\Http\Controllers\Controller;
use App\Models\UpdateLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UpdateCenterController extends Controller
{
    public function __construct(private readonly UniversalUpdateService $updates) {}

    public function index(): View
    {
        $logs = UpdateLog::query()->latest()->limit(30)->get();
        $coreVersion = (string) config('lms.core_version', '2.0.0');
        $feedUrl = $this->updates->getRemoteFeedUrl();

        return view('admin.updates.index', compact('logs', 'coreVersion', 'feedUrl'));
    }

    public function apply(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'package' => ['required', 'file', 'mimes:zip', 'max:51200'],
            'sha256' => ['required', 'string', 'size:64'],
            'signature' => ['required', 'string', 'size:64'],
        ]);

        $path = $request->file('package')->store('updates');
        $absolutePath = storage_path('app/' . $path);
        try {
            $log = $this->updates->applyVerifiedUpdate(
                $absolutePath,
                $validated['sha256'],
                $validated['signature'],
                auth()->id()
            );

            return back()->with('status', "Signed update staged & applied for {$log->target_slug} -> {$log->to_version}.");
        } catch (\Throwable $e) {
            return back()->withErrors(['update' => $e->getMessage()]);
        } finally {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
    }

    public function saveFeed(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'feed_url' => ['required', 'url', 'max:255'],
        ]);

        try {
            $this->updates->saveRemoteFeedUrl($validated['feed_url'], auth()->id());
        } catch (\Throwable $e) {
            return back()->withErrors(['feed_url' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Remote LMS release channel URL saved.');
    }

    public function checkFeed(): RedirectResponse
    {
        $result = $this->updates->checkRemoteFeed();
        if ($result['reachable']) {
            return back()->with('status', 'Update channel reachable. Latest manifest fetched cleanly.');
        }

        return back()->with('status', $result['message'] ?? 'Checked release channel.');
    }

    public function rollback(UpdateLog $log): RedirectResponse
    {
        try {
            $this->updates->rollbackUpdate($log, auth()->id());

            return back()->with('status', "Rolled back {$log->target_slug} cleanly to pre-update backup {$log->backup_reference}.");
        } catch (\Throwable $e) {
            return back()->withErrors(['rollback' => $e->getMessage()]);
        }
    }
}
