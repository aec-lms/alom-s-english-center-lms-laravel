<?php
declare(strict_types=1);
namespace Plugins\Modules\Phonetics\Providers;
use App\Core\Plugins\BasePluginServiceProvider;
final class PhoneticsServiceProvider extends BasePluginServiceProvider{public function slug():string{return'phonetics';}protected function pluginPath(string $relative=''):string{return dirname(__DIR__).($relative!==''?DIRECTORY_SEPARATOR.ltrim($relative,DIRECTORY_SEPARATOR):'');}}