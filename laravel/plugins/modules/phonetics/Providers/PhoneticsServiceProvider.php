<?php
declare(strict_types=1);
namespace Plugins\Modules\Phonetics\Providers;
use App\Core\Plugins\BasePluginServiceProvider;
use Illuminate\Support\Facades\Cache;
final class PhoneticsServiceProvider extends BasePluginServiceProvider {
 public function slug():string{return 'phonetics';}
 public function register():void{parent::register();$this->mergeConfigFrom($this->pluginPath('Config/phonetics.php'),'phonetics');$this->app->singleton(\Plugins\Modules\Phonetics\Services\CourseCatalog::class);}
 protected function pluginPath(string $relative=''):string{return dirname(__DIR__).($relative!==''?DIRECTORY_SEPARATOR.ltrim($relative,DIRECTORY_SEPARATOR):'');}
 public function onEnable():void{$this->clearPluginCache();}
 public function onDisable():void{$this->clearPluginCache();}
 public function onUninstall(bool $purgePluginData=false):void{$this->clearPluginCache();}
 private function clearPluginCache():void{Cache::forget('phonetics.settings');Cache::forget('phonetics.active_entries');}
}