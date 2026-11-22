<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Plugins\PluginManifest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PluginDashboardWidgetManifestTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/aec-plugin-widget-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            @unlink($this->directory . '/plugin.json');
            @rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_manifest_exposes_validated_dashboard_widget_metadata(): void
    {
        file_put_contents($this->directory . '/plugin.json', json_encode([
            'slug' => 'sample-plugin',
            'name' => 'Sample Plugin',
            'version' => '1.0.0',
            'dashboard_widgets' => [[
                'id' => 'overview',
                'label' => 'Sample Overview',
                'description' => 'A safe metadata-only widget.',
                'permission' => 'sample.view',
                'route' => 'sample.index',
            ]],
        ], JSON_THROW_ON_ERROR));

        $manifest = PluginManifest::fromDirectory($this->directory);

        self::assertSame([[
            'id' => 'overview',
            'label' => 'Sample Overview',
            'description' => 'A safe metadata-only widget.',
            'permission' => 'sample.view',
            'route' => 'sample.index',
        ]], $manifest->dashboardWidgets);
        self::assertSame($manifest->dashboardWidgets, $manifest->toArray()['dashboard_widgets']);
    }

    public function test_manifest_rejects_unsafe_or_duplicate_dashboard_widget_ids(): void
    {
        file_put_contents($this->directory . '/plugin.json', json_encode([
            'slug' => 'sample-plugin',
            'name' => 'Sample Plugin',
            'version' => '1.0.0',
            'dashboard_widgets' => [
                ['id' => '../escape', 'label' => 'Unsafe'],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        PluginManifest::fromDirectory($this->directory);
    }
}
