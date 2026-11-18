<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\CourseContentStudio\Http\Controllers\AdminCourseContentStudioController;
use Plugins\Modules\CourseContentStudio\Http\Controllers\CourseContentLearningController;

Route::middleware(['web','installed','auth','plugin:course-content-studio','permission:content-studio.manage'])
    ->prefix('admin/course-content-studio')->name('plugins.content-studio.admin.')
    ->group(function():void{
        Route::get('/',[AdminCourseContentStudioController::class,'index'])->name('index');
        Route::post('/categories',[AdminCourseContentStudioController::class,'storeCategory'])->name('categories.store');
        Route::post('/posts',[AdminCourseContentStudioController::class,'store'])->name('posts.store');
        Route::put('/posts/{post}',[AdminCourseContentStudioController::class,'update'])->whereNumber('post')->name('posts.update');
        Route::delete('/posts/{post}',[AdminCourseContentStudioController::class,'archive'])->whereNumber('post')->name('posts.archive');
        Route::get('/courses/{course}/builder',[AdminCourseContentStudioController::class,'builder'])->whereNumber('course')->name('builder');
        Route::put('/courses/{course}/builder',[AdminCourseContentStudioController::class,'updateBuilder'])->whereNumber('course')->name('builder.update');
    });

Route::middleware(['web','installed','plugin:course-content-studio'])->group(function():void{
    Route::get('/courses/{course}/content/{post}',[CourseContentLearningController::class,'show'])->name('plugins.content-studio.learning.show');
    Route::get('/courses/{course}/content/{post}/file',[CourseContentLearningController::class,'file'])->name('plugins.content-studio.learning.file');
    Route::post('/courses/{course}/content/{post}/complete',[CourseContentLearningController::class,'complete'])->middleware(['auth','throttle:30,1'])->name('plugins.content-studio.learning.complete');
});
