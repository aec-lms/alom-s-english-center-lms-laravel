<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseWishlist\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class CourseWishlistServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'course-wishlist';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
