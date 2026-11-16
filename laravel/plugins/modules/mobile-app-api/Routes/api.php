<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\MobileAppApi\Http\Controllers\MobileApiController;

Route::middleware(['api', 'installed', 'plugin:mobile-app-api'])
    ->prefix('api/mobile')
    ->name('plugins.mobile.')
    ->group(function (): void {
        Route::get('/config', [MobileApiController::class, 'config'])->name('config');
        Route::get('/home', [MobileApiController::class, 'home'])->name('home');
        Route::post('/register', [MobileApiController::class, 'register'])
            ->middleware('throttle:5,1')
            ->name('register');
        Route::post('/login', [MobileApiController::class, 'login'])
            ->middleware('throttle:10,1')
            ->name('login');
        Route::get('/courses', [MobileApiController::class, 'courses'])->name('courses');
        Route::get('/courses/{slug}', [MobileApiController::class, 'courseShow'])->name('courses.show');
        Route::get('/ebooks', [MobileApiController::class, 'ebooks'])->name('ebooks');
        Route::get('/products', [MobileApiController::class, 'products'])->name('products');
        Route::get('/exams', [MobileApiController::class, 'exams'])->name('exams');
        Route::get('/notices', [MobileApiController::class, 'notices'])->name('notices');
        Route::get('/certificates/verify/{code}', [MobileApiController::class, 'verifyCertificate'])->name('certificates.verify');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('/courses/{slug}/lessons', [MobileApiController::class, 'lessons'])->name('courses.lessons');
            Route::get('/me', [MobileApiController::class, 'me'])->name('me');
            Route::post('/push-token', [MobileApiController::class, 'registerPushToken'])->name('push_token');
            Route::post('/logout', [MobileApiController::class, 'logout'])->name('logout');
        });
    });
