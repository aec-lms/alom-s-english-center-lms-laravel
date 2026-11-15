<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Installer\FreshInstallerService;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Tests\TestCase;

class FreshInstallerTest extends TestCase
{
    public function test_fresh_installer_rejects_non_empty_database(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE legacy_students (id INTEGER PRIMARY KEY, name TEXT)');

        $installer = app(FreshInstallerService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty fresh database is required');
        $installer->assertFreshEmptyDatabase($pdo, ':memory:', 'sqlite');
    }

    public function test_fresh_installer_succeeds_on_empty_sqlite_and_seeds_no_legacy_records(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $installer = app(FreshInstallerService::class);
        $installer->assertFreshEmptyDatabase($pdo, ':memory:', 'sqlite');

        $this->markSystemInstalled();

        $this->assertSame('false', SystemSetting::query()->where('key', 'legacy_records_imported')->value('value'));
        $this->assertSame(0, User::query()->count());
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('legacy_students'));
    }

    public function test_installer_routes_are_locked_after_installation(): void
    {
        $this->markSystemInstalled();

        $this->get('/install')->assertStatus(404);
    }
}
