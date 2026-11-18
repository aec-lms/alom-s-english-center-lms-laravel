<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\CourseAssessments\Http\Controllers\StudentAssessmentController;

foreach (['api/v1', 'api/mobile'] as $prefix) {
    Route::middleware(['api', 'installed', 'plugin:course-assessments', 'auth:sanctum'])
        ->prefix($prefix.'/my-learning/assessments')
        ->group(function (): void {
            Route::get('/', [StudentAssessmentController::class, 'apiIndex'])->middleware('throttle:60,1');
            Route::get('/history', [StudentAssessmentController::class, 'apiHistory'])->middleware('throttle:60,1');
            Route::post('/{set}/start', [StudentAssessmentController::class, 'apiStart'])->whereNumber('set')->middleware('throttle:10,1');
            Route::get('/attempts/{attempt}', [StudentAssessmentController::class, 'apiTake'])->whereNumber('attempt')->middleware('throttle:60,1');
            Route::put('/attempts/{attempt}/questions/{question}', [StudentAssessmentController::class, 'answer'])->whereNumber('attempt')->whereNumber('question')->middleware('throttle:120,1');
            Route::post('/attempts/{attempt}/submit', [StudentAssessmentController::class, 'apiSubmit'])->whereNumber('attempt')->middleware('throttle:10,1');
        });
}
