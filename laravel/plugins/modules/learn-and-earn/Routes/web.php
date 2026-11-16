<?php
declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use Plugins\Modules\LearnAndEarn\Http\Controllers\LearnAndEarnController;
Route::middleware(['web','installed','auth','plugin:learn-and-earn','permission:learn_earn.manage'])->prefix('admin/learn-and-earn')->name('plugins.learn_earn.')->group(function(){
 Route::get('/',[LearnAndEarnController::class,'index'])->name('index');
 Route::post('/categories',[LearnAndEarnController::class,'storeCategory'])->name('categories.store');
 Route::post('/questions',[LearnAndEarnController::class,'storeQuestion'])->name('questions.store');
 Route::post('/exams',[LearnAndEarnController::class,'storeExam'])->name('exams.store');
 Route::post('/exams/{exam}/grant-access',[LearnAndEarnController::class,'grantAccess'])->name('exams.grant');
});