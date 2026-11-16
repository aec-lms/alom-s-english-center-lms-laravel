<?php
declare(strict_types=1);
namespace App\Console\Commands;
use App\Core\Monitoring\HealthMonitorService;
use Illuminate\Console\Command;
class CheckSystemHealthCommand extends Command
{
    protected $signature = 'lms:health-check {--recover : Attempt safe runtime-directory and database reconnection recovery}';
    protected $description = 'Check LMS runtime health and optionally attempt safe automatic recovery.';
    public function handle(HealthMonitorService $monitor): int
    {
        $report = $monitor->report((bool) $this->option('recover'));
        $this->line('AEC LMS status: ' . strtoupper($report['status']));
        foreach ($report['checks'] as $name => $check) $this->line(sprintf('[%s] %s: %s', strtoupper($check['status']), $name, $check['message']));
        foreach ($report['recoveries'] as $name => $message) $this->info("Recovery {$name}: {$message}");
        return $report['failed_count'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
