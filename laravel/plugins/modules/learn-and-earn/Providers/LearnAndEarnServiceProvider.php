<?php
declare(strict_types=1);
namespace Plugins\Modules\LearnAndEarn\Providers;
use App\Core\Plugins\BasePluginServiceProvider;
class LearnAndEarnServiceProvider extends BasePluginServiceProvider {
 public function slug(): string { return 'learn-and-earn'; }
 protected function pluginPath(string $relative = ''): string { return dirname(__DIR__).($relative !== '' ? DIRECTORY_SEPARATOR.ltrim($relative,DIRECTORY_SEPARATOR) : ''); }
}