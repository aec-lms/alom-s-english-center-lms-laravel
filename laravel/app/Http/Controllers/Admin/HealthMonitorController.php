<?php
declare(strict_types=1);
namespace App\Http\Controllers\Admin;
use App\Core\Monitoring\HealthMonitorService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
class HealthMonitorController extends Controller
{
    public function index(HealthMonitorService $monitor): View
    {
        $report = $monitor->latest() ?? $monitor->report(false);
        return view('admin.health.index', compact('report'));
    }
    public function check(HealthMonitorService $monitor): RedirectResponse
    {
        $report = $monitor->report(true);
        $message = $report['failed_count'] === 0 ? 'Health check completed: all monitored services are healthy.' : 'Health check completed. Review failed checks; only safe automatic recovery was attempted.';
        return back()->with('status', $message);
    }
}
