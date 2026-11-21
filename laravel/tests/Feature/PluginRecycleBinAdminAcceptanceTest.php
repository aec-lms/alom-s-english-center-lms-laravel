<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use App\Models\PluginArchive;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PluginRecycleBinAdminAcceptanceTest extends TestCase
{
    private function createProbePlugin(string $slug): string
    {
        $modulePath = base_path('plugins/modules/' . $slug);
        File::ensureDirectoryExists($modulePath);
        File::put($modulePath . '/plugin.json', json_encode([
            'slug' => $slug,
            'name' => 'Recycle Bin Acceptance Probe',
            'version' => '1.0.0',
            'core_compatibility' => '^2.0',
            'dependencies' => [],
            'settings_schema' => [[
                'key' => 'probe_setting',
                'label' => 'Probe setting',
                'type' => 'text',
                'secret' => false,
            ]],
        ], JSON_THROW_ON_ERROR));

        return $modulePath;
    }

    public function test_admin_can_delete_and_restore_a_plugin_package_from_the_recycle_bin(): void
    {
        $this->markSystemInstalled();
        $slug = 'recycle-bin-probe-' . strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
        $modulePath = $this->createProbePlugin($slug);
        $archivePath = null;

        try {
            $manager = app(PluginManager::class);
            $manager->syncCatalog();
            $manager->install($slug);
            $admin = $this->createUserWithRole('admin');

            $this->actingAs($admin)
                ->post('/admin/plugins/' . $slug . '/delete')
                ->assertSessionHas('status');

            $archive = PluginArchive::query()->where('plugin_slug', $slug)->firstOrFail();
            $archivePath = $archive->archive_path;
            $this->assertDirectoryDoesNotExist($modulePath);
            $this->assertFalse($manager->isInstalled($slug));

            $this->actingAs($admin)
                ->post('/admin/plugins/recycle-bin/' . $archive->id . '/restore')
                ->assertSessionHas('status');

            $this->assertDirectoryExists($modulePath);
            $this->assertTrue($manager->isInstalled($slug));
            $this->assertTrue($manager->isEnabled($slug));
            $this->assertDatabaseMissing('plugin_archives', ['id' => $archive->id]);
        } finally {
            File::deleteDirectory($modulePath);
            if ($archivePath && is_dir($archivePath)) {
                File::deleteDirectory($archivePath);
            }
        }
    }

    public function test_admin_can_permanently_delete_a_recycled_plugin_package_and_its_settings(): void
    {
        $this->markSystemInstalled();
        $slug = 'permanent-delete-probe-' . strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
        $modulePath = $this->createProbePlugin($slug);
        $archivePath = null;
        $backupPath = null;

        try {
            $manager = app(PluginManager::class);
            $manager->syncCatalog();
            $manager->install($slug);
            $manager->saveSettings($slug, ['probe_setting' => 'temporary-value']);
            $admin = $this->createUserWithRole('admin');

            $this->actingAs($admin)
                ->post('/admin/plugins/' . $slug . '/delete')
                ->assertSessionHas('status');

            $archive = PluginArchive::query()->where('plugin_slug', $slug)->firstOrFail();
            $archivePath = $archive->archive_path;

            $this->actingAs($admin)
                ->post('/admin/plugins/recycle-bin/' . $archive->id . '/permanent-delete')
                ->assertSessionHas('status');

            $this->assertDirectoryDoesNotExist($archivePath);
            $backupId = 'archive-' . (int) $archive->id . '-' . substr(hash('sha256', $slug), 0, 12);
            $backupPath = storage_path('app/plugin-permanent-delete-backups/' . $backupId);
            $this->assertDirectoryExists($backupPath);
            $this->assertFileExists($backupPath . '/plugin.json');
            $this->assertDatabaseMissing('plugin_archives', ['id' => $archive->id]);
            $this->assertDatabaseMissing('plugins', ['slug' => $slug]);
            $this->assertDatabaseMissing('plugin_settings', ['plugin_slug' => $slug]);
        } finally {
            File::deleteDirectory($modulePath);
            if ($archivePath && is_dir($archivePath)) {
                File::deleteDirectory($archivePath);
            }
            if ($backupPath && is_dir($backupPath)) {
                File::deleteDirectory($backupPath);
            }
        }
    }
    public function test_permanent_delete_rejects_a_tampered_archive_without_creating_a_recovery_copy(): void
    {
        $this->markSystemInstalled();
        $slug = 'tampered-permanent-delete-' . strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
        $modulePath = $this->createProbePlugin($slug);
        $archivePath = null;
        $backupPath = null;

        try {
            $manager = app(PluginManager::class);
            $manager->syncCatalog();
            $manager->install($slug);
            $admin = $this->createUserWithRole('admin');

            $this->actingAs($admin)->post('/admin/plugins/' . $slug . '/delete')->assertSessionHas('status');
            $archive = PluginArchive::query()->where('plugin_slug', $slug)->firstOrFail();
            $archivePath = $archive->archive_path;
            File::append($archivePath . '/plugin.json', "\\n/* tampered after archival */");

            $this->actingAs($admin)
                ->post('/admin/plugins/recycle-bin/' . $archive->id . '/permanent-delete')
                ->assertSessionHasErrors('plugin');

            $backupId = 'archive-' . (int) $archive->id . '-' . substr(hash('sha256', $slug), 0, 12);
            $backupPath = storage_path('app/plugin-permanent-delete-backups/' . $backupId);
            $this->assertDirectoryExists($archivePath);
            $this->assertDirectoryDoesNotExist($backupPath);
            $this->assertDatabaseHas('plugin_archives', ['id' => $archive->id]);
        } finally {
            File::deleteDirectory($modulePath);
            if ($archivePath && is_dir($archivePath)) {
                File::deleteDirectory($archivePath);
            }
            if ($backupPath && is_dir($backupPath)) {
                File::deleteDirectory($backupPath);
            }
        }
    }

    public function test_restore_preserves_previously_disabled_plugin_state(): void
    {
        $this->markSystemInstalled();
        $slug = 'disabled-restore-probe-' . strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
        $modulePath = $this->createProbePlugin($slug);
        $archivePath = null;

        try {
            $manager = app(PluginManager::class);
            $manager->syncCatalog();
            $manager->install($slug);
            $manager->disable($slug);
            $admin = $this->createUserWithRole('admin');

            $this->actingAs($admin)->post('/admin/plugins/' . $slug . '/delete')->assertSessionHas('status');
            $archive = PluginArchive::query()->where('plugin_slug', $slug)->firstOrFail();
            $archivePath = $archive->archive_path;
            $this->assertFalse($archive->was_enabled);
            $this->assertTrue($archive->was_installed);

            $this->actingAs($admin)->post('/admin/plugins/recycle-bin/' . $archive->id . '/restore')->assertSessionHas('status');

            $this->assertTrue($manager->isInstalled($slug));
            $this->assertFalse($manager->isEnabled($slug));
        } finally {
            File::deleteDirectory($modulePath);
            if ($archivePath && is_dir($archivePath)) File::deleteDirectory($archivePath);
        }
    }

    public function test_restore_rejects_modified_archived_plugin_package(): void
    {
        $this->markSystemInstalled();
        $slug = 'tampered-restore-probe-' . strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
        $modulePath = $this->createProbePlugin($slug);
        $archivePath = null;

        try {
            $manager = app(PluginManager::class);
            $manager->syncCatalog();
            $manager->install($slug);
            $admin = $this->createUserWithRole('admin');
            $this->actingAs($admin)->post('/admin/plugins/' . $slug . '/delete')->assertSessionHas('status');

            $archive = PluginArchive::query()->where('plugin_slug', $slug)->firstOrFail();
            $archivePath = $archive->archive_path;
            File::append($archivePath . '/plugin.json', "\n/* tampered */");

            $this->actingAs($admin)->post('/admin/plugins/recycle-bin/' . $archive->id . '/restore')
                ->assertSessionHasErrors('plugin');
            $this->assertDirectoryDoesNotExist($modulePath);
            $this->assertDirectoryExists($archivePath);
            $this->assertDatabaseHas('plugin_archives', ['id' => $archive->id]);
        } finally {
            File::deleteDirectory($modulePath);
            if ($archivePath && is_dir($archivePath)) File::deleteDirectory($archivePath);
        }
    }

    public function test_partial_recycle_metadata_write_restores_package_and_previous_lifecycle_state(): void
    {
        $this->markSystemInstalled();
        $slug = 'partial-metadata-probe-' . strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
        $modulePath = $this->createProbePlugin($slug);
        $archivePath = null;

        try {
            $manager = app(PluginManager::class);
            $manager->syncCatalog();
            $manager->install($slug);
            $this->assertTrue($manager->isInstalled($slug));
            $this->assertTrue($manager->isEnabled($slug));

            // File::put returning a positive short count simulates a truncated
            // metadata file; the archive must not be committed or orphaned.
            File::partialMock()->shouldReceive('put')->once()->andReturn(1);

            try {
                $manager->deleteToRecycleBin($slug);
                $this->fail('A partial recycle-bin metadata write must abort plugin deletion.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('original package and lifecycle state were restored', $exception->getMessage());
            }

            $this->assertDirectoryExists($modulePath);
            $this->assertTrue($manager->isInstalled($slug));
            $this->assertTrue($manager->isEnabled($slug));
            $this->assertFalse(collect($manager->recycledPlugins())->contains(fn (array $item): bool => ($item['slug'] ?? null) === $slug));
        } finally {
            File::deleteDirectory($modulePath);
            if ($archivePath && is_dir($archivePath)) {
                File::deleteDirectory($archivePath);
            }
        }
    }


    public function test_failed_archive_rollback_reports_the_actual_retained_archive_path(): void
    {
        $this->markSystemInstalled();
        $slug = 'rollback-failure-probe-' . strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
        $modulePath = $this->createProbePlugin($slug);
        $archivePath = null;

        try {
            $manager = app(PluginManager::class);
            $manager->syncCatalog();
            $manager->install($slug);

            $moveCalls = 0;
            $file = File::partialMock();
            $file->shouldReceive('put')->once()->andReturn(1);
            $file->shouldReceive('moveDirectory')->twice()->andReturnUsing(
                function (string $from, string $to) use (&$moveCalls): bool {
                    $moveCalls++;
                    return $moveCalls === 1 ? rename($from, $to) : false;
                }
            );

            try {
                $manager->deleteToRecycleBin($slug);
                $this->fail('Metadata write failure with failed rollback must report retained archive.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('without a committed metadata index', $exception->getMessage());
                $this->assertStringContainsString('plugin-recycle-bin', $exception->getMessage());
            }

            $archives = glob(storage_path('app/plugin-recycle-bin/' . $slug . '-*')) ?: [];
            $this->assertCount(1, $archives);
            $archivePath = $archives[0];
            $this->assertDirectoryExists($archivePath);
            $this->assertDirectoryDoesNotExist($modulePath);
            $this->assertFalse($manager->isInstalled($slug));
            $this->assertFalse($manager->isEnabled($slug));
            $this->assertSame([], array_values(array_filter(
                $manager->recycledPlugins(),
                fn (array $item): bool => ($item['slug'] ?? null) === $slug
            )));
        } finally {
            File::deleteDirectory($modulePath);
            if ($archivePath && is_dir($archivePath)) {
                File::deleteDirectory($archivePath);
            }
        }
    }

    public function test_checksum_mismatch_after_archive_rollback_never_reinstalls_plugin(): void
    {
        $this->markSystemInstalled();
        $slug = 'rollback-checksum-probe-' . strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
        $modulePath = $this->createProbePlugin($slug);

        try {
            $manager = app(PluginManager::class);
            $manager->syncCatalog();
            $manager->install($slug);

            $moveCalls = 0;
            $file = File::partialMock();
            $file->shouldReceive('put')->once()->andReturn(1);
            $file->shouldReceive('moveDirectory')->twice()->andReturnUsing(
                function (string $from, string $to) use (&$moveCalls): bool {
                    $moveCalls++;
                    if (!rename($from, $to)) {
                        return false;
                    }
                    if ($moveCalls === 2) {
                        file_put_contents($to . '/plugin.json', "\n/* checksum mismatch during rollback */", FILE_APPEND);
                    }
                    return true;
                }
            );

            try {
                $manager->deleteToRecycleBin($slug);
                $this->fail('A checksum mismatch during rollback must fail closed.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('failed checksum verification', $exception->getMessage());
                $this->assertStringContainsString($modulePath, $exception->getMessage());
            }

            $this->assertDirectoryExists($modulePath);
            $this->assertFalse($manager->isInstalled($slug));
            $this->assertFalse($manager->isEnabled($slug));
        } finally {
            File::deleteDirectory($modulePath);
        }
    }

}
