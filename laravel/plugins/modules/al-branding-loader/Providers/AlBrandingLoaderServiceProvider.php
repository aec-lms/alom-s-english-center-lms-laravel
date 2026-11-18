<?php
declare(strict_types=1);
namespace Plugins\Modules\AlBrandingLoader\Providers;
use App\Core\Plugins\BasePluginServiceProvider;
final class AlBrandingLoaderServiceProvider extends BasePluginServiceProvider {
 public function slug():string{return 'al-branding-loader';}
 protected function pluginPath(string $relative=''):string{return dirname(__DIR__).($relative!==''?DIRECTORY_SEPARATOR.ltrim($relative,DIRECTORY_SEPARATOR):'');}
}