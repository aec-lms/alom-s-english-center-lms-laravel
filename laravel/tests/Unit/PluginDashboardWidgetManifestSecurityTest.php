<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Plugins\PluginManifest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PluginDashboardWidgetManifestSecurityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/aec-widget-security-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/Resources/views/dashboard', 0775, true);
        file_put_contents($this->directory . '/Resources/views/dashboard/widget.blade.php', '<section>Safe widget</section>');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            @unlink($this->directory . '/Resources/views/dashboard/widget.blade.php');
            @rmdir($this->directory . '/Resources/views/dashboard');
            @rmdir($this->directory . '/Resources/views');
            @rmdir($this->directory . '/Resources');
            @unlink($this->directory . '/plugin.json');
            @rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function writeManifest(array $widgets): void
    {
        file_put_contents($this->directory . '/plugin.json', json_encode([
            'slug' => 'sample-plugin',
            'name' => 'Sample Plugin',
            'version' => '1.0.0',
            'dashboard_widgets' => $widgets,
        ], JSON_THROW_ON_ERROR));
    }

    public function test_manifest_accepts_a_widget_view_inside_its_own_views_directory(): void
    {
        $this->writeManifest([[
            'key' => 'overview',
            'label' => 'Overview',
            'view' => 'dashboard.widget',
        ]]);

        $manifest = PluginManifest::fromDirectory($this->directory);

        self::assertSame('sample-plugin.overview', $manifest->dashboardWidgets[0]['key']);
    }

    public function test_manifest_rejects_a_symlinked_widget_view_outside_the_plugin(): void
    {
        $outside = $this->directory . '-outside.blade.php';
        file_put_contents($outside, '<section>Outside</section>');
        $link = $this->directory . '/Resources/views/dashboard/external.blade.php';
        if (!symlink($outside, $link)) {
            @unlink($outside);
            self::markTestSkipped('Symlinks are not available in this test environment.');
        }

        try {
            $this->writeManifest([[
                'key' => 'external',
                'label' => 'External',
                'view' => 'dashboard.external',
            ]]);

            $this->expectException(InvalidArgumentException::class);
            PluginManifest::fromDirectory($this->directory);
        } finally {
            @unlink($link);
            @unlink($outside);
        }
    }

    public function test_manifest_rejects_more_than_twenty_dashboard_widgets(): void
    {
        $widgets = [];
        for ($index = 1; $index <= 21; $index++) {
            $widgets[] = [
                'key' => 'widget-' . $index,
                'label' => 'Widget ' . $index,
                'view' => 'dashboard.widget',
            ];
        }
        $this->writeManifest($widgets);

        $this->expectException(InvalidArgumentException::class);
        PluginManifest::fromDirectory($this->directory);
    }
}
