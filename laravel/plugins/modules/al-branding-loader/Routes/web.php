<?php
declare(strict_types=1);
use Illuminate\Support\Facades\Route;use Plugins\Modules\AlBrandingLoader\Http\Controllers\LoaderSettingsController;
Route::middleware(['web','installed','auth','plugin:al-branding-loader','permission:al-branding-loader.manage'])->prefix('admin/al-branding-loader')->name('plugins.al-branding-loader.')->group(function():void{Route::get('/',[LoaderSettingsController::class,'edit'])->name('edit');Route::put('/',[LoaderSettingsController::class,'update'])->name('update');});