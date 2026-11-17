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
            $this->assertDatabaseMissing('plugin_archives', ['id' => $archive->id]);
            $this->assertDatabaseMissing('plugins', ['slug' => $slug]);
            $this->assertDatabaseMissing('plugin_settings', ['plugin_slug' => $slug]);
        } finally {
            File::deleteDirectory($modulePath);
            if ($archivePath && is_dir($archivePath)) {
                File::deleteDirectory($archivePath);
            }
        }
    }
}
