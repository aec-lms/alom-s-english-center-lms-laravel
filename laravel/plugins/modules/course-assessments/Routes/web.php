<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\CourseAssessments\Http\Controllers\AdminAssessmentController;
use Plugins\Modules\CourseAssessments\Http\Controllers\AssessmentGradingController;
use Plugins\Modules\CourseAssessments\Http\Controllers\StudentAssessmentController;

Route::middleware(['web','installed','auth','plugin:course-assessments','permission:course_assessments.manage'])
    ->prefix('admin/course-assessments')->name('plugins.course-assessments.admin.')
    ->group(function():void{
        Route::get('/',[AdminAssessmentController::class,'index'])->name('index');
        Route::post('/',[AdminAssessmentController::class,'store'])->middleware('throttle:10,1')->name('store');
        Route::patch('/{set}/publish',[AdminAssessmentController::class,'publish'])->whereNumber('set')->name('publish');
    });

Route::middleware(['web','installed','auth','plugin:course-assessments','permission:course_assessments.grade'])
    ->prefix('admin/course-assessments/grading')->name('plugins.course-assessments.grading.')
    ->group(function():void{
        Route::get('/',[AssessmentGradingController::class,'index'])->name('index');
        Route::patch('/{attempt}',[AssessmentGradingController::class,'grade'])->whereNumber('attempt')->middleware('throttle:20,1')->name('update');
    });

Route::middleware(['web','installed','auth','plugin:course-assessments'])
    ->prefix('my-learning/assessments')->name('plugins.course-assessments.student.')
    ->group(function():void{
        Route::get('/',[StudentAssessmentController::class,'index'])->name('index');
        Route::post('/{set}/start',[StudentAssessmentController::class,'start'])->whereNumber('set')->middleware('throttle:10,1')->name('start');
        Route::get('/attempts/{attempt}',[StudentAssessmentController::class,'take'])->whereNumber('attempt')->name('take');
        Route::put('/attempts/{attempt}/questions/{question}',[StudentAssessmentController::class,'answer'])->whereNumber('attempt')->whereNumber('question')->middleware('throttle:120,1')->name('answer');
        Route::post('/attempts/{attempt}/submit',[StudentAssessmentController::class,'submit'])->whereNumber('attempt')->middleware('throttle:10,1')->name('submit');
        Route::get('/history',[StudentAssessmentController::class,'history'])->name('history');
    });
