<?php
declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use Plugins\Modules\FlashSale\Http\Controllers\FlashSaleController;
Route::middleware(['web','installed','plugin:flash-sale'])->group(function(){
 Route::get('/flash-sale',[FlashSaleController::class,'publicIndex'])->name('plugins.flash_sale.public');
 Route::middleware(['auth','permission:flash_sale.manage'])->prefix('admin/flash-sales')->name('plugins.flash_sale.')->group(function(){
  Route::get('/',[FlashSaleController::class,'index'])->name('index');Route::post('/',[FlashSaleController::class,'store'])->name('store');
  Route::put('/{sale}',[FlashSaleController::class,'update'])->name('update');Route::delete('/{sale}',[FlashSaleController::class,'destroy'])->name('destroy');Route::post('/page-settings',[FlashSaleController::class,'settings'])->name('settings');
 });
});