<?php
declare(strict_types=1);
namespace App\Core\Monitoring;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
class HealthMonitorService
{
    private const SNAPSHOT_KEY = 'aec:health:last-report';
    public function report(bool $attemptSafeRecovery = false): array
    {
        $checks = []; $recoveries = [];
        $checks['database'] = $this->check(function (): string {
            DB::connection()->select('SELECT 1'); return 'Database connection and query are healthy.';
        }, $attemptSafeRecovery ? function (): string {
            DB::purge(); DB::reconnect(); DB::connection()->select('SELECT 1'); return 'Database connection was re-established.';
        } : null, $recoveries);
        $checks['cache'] = $this->check(function (): string {
            $key = 'aec:health:probe:' . bin2hex(random_bytes(8));
            Cache::put($key, 'ok', 30); $healthy = Cache::get($key) === 'ok'; Cache::forget($key);
            if (!$healthy) throw new \RuntimeException('Cache read/write verification failed.');
            return 'Cache read/write verification passed.';
        }, null, $recoveries);
        $runtimePaths = [storage_path('framework/cache'), storage_path('framework/sessions'), storage_path('framework/views'), storage_path('logs')];
        $checks['storage'] = $this->check(function () use ($runtimePaths): string {
            foreach ($runtimePaths as $path) if (!is_dir($path) || !is_writable($path)) throw new \RuntimeException('A required Laravel storage directory is missing or not writable.');
            $probe = storage_path('logs/.aec-health-' . bin2hex(random_bytes(6)));
            if (file_put_contents($probe, 'ok', LOCK_EX) === false) throw new \RuntimeException('Storage write probe failed.');
            @unlink($probe); return 'Laravel storage directories are writable.';
        }, $attemptSafeRecovery ? function () use ($runtimePaths): string {
            foreach ($runtimePaths as $path) {
                if (is_link($path)) throw new \RuntimeException('Safe recovery refused a symbolic-link storage path.');
                if (!is_dir($path)) {
                    $parent = dirname($path);
                    if (!is_dir($parent) || !is_writable($parent) || !mkdir($path, 0750, true)) throw new \RuntimeException('Safe recovery could not create a missing storage directory.');
                }
                if (!is_writable($path)) throw new \RuntimeException('Storage permissions require hosting-provider review; automatic chmod is disabled.');
            }
            return 'Missing Laravel runtime directories were safely recreated; permissions were not changed.';
        } : null, $recoveries);
        $checks['private_storage'] = $this->check(function (): string {
            $path = (string) config('lms.private_storage_path');
            if (is_link($path) || !is_dir($path) || !is_writable($path)) throw new \RuntimeException('Private storage is missing, linked, or not writable.');
            return 'Private storage is present and writable.';
        }, null, $recoveries);
        $checks['backup_storage'] = $this->check(function (): string {
            $path = (string) config('lms.backup_path');
            if (is_link($path) || !is_dir($path) || !is_writable($path)) throw new \RuntimeException('Backup storage is missing, linked, or not writable.');
            return 'Backup storage is present and writable.';
        }, null, $recoveries);
        $checks['disk_space'] = $this->check(function (): string {
            $free = @disk_free_space(storage_path());
            if ($free === false) throw new \RuntimeException('Disk free space could not be determined.');
            if ($free < (int) config('lms.health_min_free_disk_bytes', 268435456)) throw new \RuntimeException('Available disk space is below the configured safety threshold.');
            return 'Available disk space is above the configured safety threshold.';
        }, null, $recoveries);
        $checks['php_runtime'] = $this->check(function (): string {
            if (PHP_VERSION_ID < 80300) throw new \RuntimeException('PHP 8.3 or newer is required.');
            return 'PHP ' . PHP_VERSION . ' is running (memory_limit ' . (string) ini_get('memory_limit') . ').';
        }, null, $recoveries);
        $failed = array_filter($checks, static fn (array $check): bool => $check['status'] === 'failed');
        $report = ['status' => $failed === [] ? 'healthy' : (count($failed) === count($checks) ? 'critical' : 'degraded'),
            'checked_at' => now()->toIso8601String(), 'checks' => $checks, 'recoveries' => $recoveries, 'failed_count' => count($failed)];
        try { Cache::put(self::SNAPSHOT_KEY, $report, now()->addMinutes(10)); }
        catch (Throwable $e) { Log::warning('AEC health report could not be cached.', ['exception' => $e::class]); }
        if ($failed !== []) {
            Log::warning('AEC LMS health check reported degraded services.', ['status' => $report['status'], 'failed_checks' => array_keys($failed)]);
            $this->notifyAdmin($report);
        }
        return $report;
    }
    public function latest(): ?array
    {
        try { $report = Cache::get(self::SNAPSHOT_KEY); return is_array($report) ? $report : null; }
        catch (Throwable) { return null; }
    }
    private function check(callable $test, ?callable $repair, array &$recoveries): array
    {
        try { return ['status' => 'ok', 'message' => $test()]; }
        catch (Throwable $e) {
            $message = $this->safeMessage($e);
            if ($repair !== null) {
                try { $recoveries['safe_runtime'] = $repair(); $verified = $test(); return ['status' => 'recovered', 'message' => $verified . ' Safe recovery verified.']; }
                catch (Throwable $repairException) { $message .= ' Recovery attempt failed: ' . $this->safeMessage($repairException); }
            }
            return ['status' => 'failed', 'message' => $message];
        }
    }
    private function ensurePrivateDirectory(string $path): string
    {
        if ($path === '' || is_link($path)) throw new \\RuntimeException('Safe recovery refused an empty or symbolic-link private path.');
        if (!is_dir($path)) {
            $parent = dirname($path);
            if (!is_dir($parent) || !is_writable($parent) || !mkdir($path, 0700, true)) throw new \\RuntimeException('Safe recovery could not create a private storage directory.');
        }
        if (!is_writable($path)) throw new \\RuntimeException('Private directory permissions require hosting-provider review; automatic chmod is disabled.');
        return 'Missing private storage directory was created with restrictive permissions.';
    }
    private function safeMessage(Throwable $e): string
    {
        return preg_match('/password|secret|token|host|sql|select|connection string/i', $e->getMessage())
            ? 'Health check failed; inspect the protected server log.' : mb_substr($e->getMessage(), 0, 180);
    }
    private function notifyAdmin(array $report): void
    {
        $recipient = trim((string) config('lms.health_alert_email', ''));
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) return;
        try {
            $key = 'aec:health:alert-cooldown:' . hash('sha256', $recipient);
            if (!Cache::add($key, true, now()->addMinutes(15))) return;
            $failed = collect($report['checks'])->filter(fn (array $c): bool => $c['status'] === 'failed')->keys()->implode(', ');
            Mail::raw("AEC LMS health status: {$report['status']}\nFailed checks: {$failed}\nChecked at: {$report['checked_at']}\nReview the Admin Health Monitor and protected logs.",
                fn ($message) => $message->to($recipient)->subject('[AEC LMS] Website health alert'));
        } catch (Throwable $e) { Log::warning('AEC LMS health alert could not be delivered.', ['exception' => $e::class]); }
    }
}
