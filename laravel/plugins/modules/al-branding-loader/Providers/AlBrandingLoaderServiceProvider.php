<?php
declare(strict_types=1);
namespace Plugins\Modules\AlBrandingLoader\Providers;
use App\Core\Plugins\BasePluginServiceProvider;
use Illuminate\Support\Facades\Cache;
final class AlBrandingLoaderServiceProvider extends BasePluginServiceProvider {
 public function slug():string{return 'al-branding-loader';}
 protected function pluginPath(string $relative=''):string{return dirname(__DIR__).($relative!==''?DIRECTORY_SEPARATOR.ltrim($relative,DIRECTORY_SEPARATOR):'');}
 public function onEnable():void{Cache::forget('al-branding-loader.settings');}
 public function onDisable():void{Cache::forget('al-branding-loader.settings');}
 public function onUninstall(bool $purgePluginData=false):void{Cache::forget('al-branding-loader.settings');}
}