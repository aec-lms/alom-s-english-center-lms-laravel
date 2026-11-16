<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Core\Monitoring\HealthMonitorService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
class HealthMonitorTest extends TestCase
{
    public function test_health_report_checks_core_runtime_services_and_is_cached(): void
    {
        $this->markSystemInstalled();
        $report = app(HealthMonitorService::class)->report(false);
        foreach (['database', 'cache', 'storage', 'private_storage', 'backup_storage', 'queue', 'disk_space', 'php_runtime', 'runtime_resources'] as $check) $this->assertArrayHasKey($check, $report['checks']);
        $this->assertSame([], $report['recoveries']);
        $this->assertSame($report, Cache::get('aec:health:last-report'));
    }
    public function test_scheduled_backup_command_creates_an_encrypted_database_backup(): void
    {
        $this->markSystemInstalled();
        $this->artisan('lms:backup-create', ['--without-files' => true])->assertExitCode(0);
        $this->assertDatabaseHas('backups', ['is_encrypted' => true, 'includes_files' => false]);
    }
    public function test_health_report_does_not_expose_sql_or_secret_values(): void
    {
        $this->markSystemInstalled();
        $encoded = json_encode(app(HealthMonitorService::class)->report(false));
        $this->assertStringNotContainsString('password=', strtolower($encoded));
        $this->assertStringNotContainsString('secret=', strtolower($encoded));
        $this->assertStringNotContainsString('select 1', strtolower($encoded));
    }
    public function test_health_monitor_admin_page_requires_dashboard_permission(): void
    {
        $this->markSystemInstalled();
        $this->actingAs($this->createUserWithRole('student'))->get('/admin/health')->assertForbidden();
    }
    public function test_health_monitor_admin_page_is_available_to_administrator(): void
    {
        $this->markSystemInstalled();
        $this->actingAs($this->createUserWithRole('admin'))->get('/admin/health')->assertOk()->assertSee('System Health &amp; Recovery', false);
    }

    public function test_safe_recovery_recreates_missing_private_and_backup_storage(): void
    {
        $this->markSystemInstalled();
        $privatePath = sys_get_temp_dir() . '/aec-private-recovery-' . uniqid('', true);
        $backupPath = sys_get_temp_dir() . '/aec-backup-recovery-' . uniqid('', true);
        config([
            'lms.private_storage_path' => $privatePath,
            'lms.backup_path' => $backupPath,
        ]);

        $report = app(HealthMonitorService::class)->report(true);

        $this->assertDirectoryExists($privatePath);
        $this->assertDirectoryExists($backupPath);
        $this->assertSame('recovered', $report['checks']['private_storage']['status']);
        $this->assertSame('recovered', $report['checks']['backup_storage']['status']);

        @rmdir($privatePath);
        @rmdir($backupPath);
    }

    public function test_safe_recovery_refuses_symbolic_link_private_storage(): void
    {
        $this->markSystemInstalled();
        $target = sys_get_temp_dir() . '/aec-private-target-' . uniqid('', true);
        $link = sys_get_temp_dir() . '/aec-private-link-' . uniqid('', true);
        mkdir($target, 0700, true);
        if (!symlink($target, $link)) {
            $this->markTestSkipped('Symbolic links are unavailable in this test environment.');
        }
        config(['lms.private_storage_path' => $link]);

        try {
            $report = app(HealthMonitorService::class)->report(true);
            $this->assertSame('failed', $report['checks']['private_storage']['status']);
            $this->assertStringContainsString('symbolic-link', $report['checks']['private_storage']['message']);
            $this->assertDirectoryExists($target);
        } finally {
            @unlink($link);
            @rmdir($target);
        }
    }
}
