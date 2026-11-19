<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\BasePluginServiceProvider;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PluginTranslationLoadingTest extends TestCase
{
    public function test_plugin_service_provider_loads_its_own_json_translations(): void
    {
        $directory = storage_path('framework/testing/plugin-language-' . bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($directory . DIRECTORY_SEPARATOR . 'lang');
        File::put($directory . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'en.json', json_encode([
            'Plugin greeting' => 'Hello from plugin',
        ], JSON_THROW_ON_ERROR));
        File::put($directory . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'bn.json', json_encode([
            'Plugin greeting' => 'প্লাগইন থেকে শুভেচ্ছা',
        ], JSON_THROW_ON_ERROR));

        try {
            $provider = new class($this->app, $directory) extends BasePluginServiceProvider {
                public function __construct($app, private readonly string $directory)
                {
                    parent::__construct($app);
                }

                public function slug(): string
                {
                    return 'translation-probe';
                }

                protected function pluginPath(string $relative = ''): string
                {
                    return $this->directory . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
                }
            };

            $provider->boot();
            app()->setLocale('bn');
            $this->assertSame('প্লাগইন থেকে শুভেচ্ছা', __('Plugin greeting'));
        } finally {
            File::deleteDirectory($directory);
            app()->setLocale('en');
        }
    }
}
