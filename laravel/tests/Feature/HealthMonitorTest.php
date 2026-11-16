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
        foreach (['database', 'cache', 'storage', 'private_storage', 'backup_storage', 'disk_space', 'php_runtime'] as $check) $this->assertArrayHasKey($check, $report['checks']);
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
}
