<?php
declare(strict_types=1);
namespace Plugins\Modules\FlashSale\Providers;
use App\Core\Plugins\BasePluginServiceProvider;
class FlashSaleServiceProvider extends BasePluginServiceProvider {public function slug():string{return 'flash-sale';}protected function pluginPath(string $relative=''):string{return dirname(__DIR__).($relative!==''?DIRECTORY_SEPARATOR.ltrim($relative,DIRECTORY_SEPARATOR):'');}}