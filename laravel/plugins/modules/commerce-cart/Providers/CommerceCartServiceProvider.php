<?php

declare(strict_types=1);

namespace Plugins\Modules\CommerceCart\Providers;

use App\Core\Plugins\BasePluginServiceProvider;
use Plugins\Modules\CommerceCart\Services\CommerceCatalog;

// Test workers may rebuild the Laravel container while retaining Composer's
// process-wide class loader. Keep provider inclusion idempotent in that case.
if (!class_exists(__NAMESPACE__ . '\\CommerceCartServiceProvider', false)) {
    class CommerceCartServiceProvider extends BasePluginServiceProvider
    {
        public function slug(): string { return 'commerce-cart'; }

        public function register(): void
        {
            $this->app->singleton(CommerceCatalog::class);
        }

        protected function pluginPath(string $relative = ''): string
        {
            return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
        }
    }
}
