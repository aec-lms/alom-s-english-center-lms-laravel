<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\MobileAppApi\Http\Controllers\MobileApiController;
use Plugins\Modules\MobileAppApi\Http\Controllers\InstructorMobileApiController;

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
            Route::get('/instructor/courses', [InstructorMobileApiController::class, 'courses'])->name('instructor.courses');
            Route::get('/instructor/attendance/policy', [InstructorMobileApiController::class, 'attendancePolicy'])->name('instructor.attendance.policy');
            Route::post('/instructor/attendance/gps-consent', [InstructorMobileApiController::class, 'grantAttendanceGpsConsent'])->middleware('throttle:5,1')->name('instructor.attendance.consent.grant');
            Route::delete('/instructor/attendance/gps-consent', [InstructorMobileApiController::class, 'revokeAttendanceGpsConsent'])->middleware('throttle:5,1')->name('instructor.attendance.consent.revoke');
            Route::post('/instructor/batches/{batch}/attendance/qr', [InstructorMobileApiController::class, 'createAttendanceQr'])->whereNumber('batch')->middleware('throttle:10,1')->name('instructor.attendance.qr');
            Route::get('/instructor/notices', [InstructorMobileApiController::class, 'notices'])->name('instructor.notices');
            Route::post('/instructor/notices/{notice}/read', [InstructorMobileApiController::class, 'markNoticeRead'])->whereNumber('notice')->middleware('throttle:60,1')->name('instructor.notices.read');
            Route::get('/instructor/live-classes', [InstructorMobileApiController::class, 'liveClasses'])->name('instructor.live-classes');
            Route::post('/instructor/batches/{batch}/live-classes', [InstructorMobileApiController::class, 'storeLiveClass'])->whereNumber('batch')->middleware('throttle:10,1')->name('instructor.live-classes.store');
            Route::patch('/instructor/live-classes/{liveClass}', [InstructorMobileApiController::class, 'updateLiveClass'])->whereNumber('liveClass')->middleware('throttle:20,1')->name('instructor.live-classes.update');
            Route::get('/instructor/courses/{slug}/students', [InstructorMobileApiController::class, 'courseStudents'])->name('instructor.courses.students');
            Route::get('/instructor/batches', [InstructorMobileApiController::class, 'batches'])->name('instructor.batches');
            Route::get('/instructor/batches/{batch}', [InstructorMobileApiController::class, 'showBatch'])->whereNumber('batch')->name('instructor.batches.show');
            Route::get('/instructor/assignments', [InstructorMobileApiController::class, 'assignments'])->name('instructor.assignments');
            Route::post('/instructor/batches/{batch}/assignments', [InstructorMobileApiController::class, 'storeAssignment'])->whereNumber('batch')->middleware('throttle:10,1')->name('instructor.assignments.store');
            Route::get('/instructor/assignments/{assignment}/submissions', [InstructorMobileApiController::class, 'submissions'])->whereNumber('assignment')->name('instructor.assignments.submissions');
            Route::patch('/instructor/assignments/{assignment}/submissions/{submission}', [InstructorMobileApiController::class, 'grade'])->whereNumber('assignment')->whereNumber('submission')->middleware('throttle:20,1')->name('instructor.assignments.grade');
            Route::get('/instructor/exams', [InstructorMobileApiController::class, 'exams'])->name('instructor.exams');
            Route::get('/instructor/exams/{exam}/results', [InstructorMobileApiController::class, 'examResults'])->whereNumber('exam')->name('instructor.exams.results');
            Route::get('/instructor/courses/{slug}/lessons', [InstructorMobileApiController::class, 'lessons'])->name('instructor.lessons');
            Route::post('/instructor/courses/{slug}/lessons', [InstructorMobileApiController::class, 'storeLesson'])->middleware('throttle:20,1')->name('instructor.lessons.store');
            Route::put('/instructor/courses/{slug}/lessons/{lesson}', [InstructorMobileApiController::class, 'updateLesson'])->whereNumber('lesson')->middleware('throttle:30,1')->name('instructor.lessons.update');
            Route::delete('/instructor/courses/{slug}/lessons/{lesson}', [InstructorMobileApiController::class, 'destroyLesson'])->whereNumber('lesson')->middleware('throttle:10,1')->name('instructor.lessons.destroy');
            Route::get('/me', [MobileApiController::class, 'me'])->name('me');
            Route::post('/push-token', [MobileApiController::class, 'registerPushToken'])->middleware('throttle:30,1')->name('push_token');
            Route::post('/logout', [MobileApiController::class, 'logout'])->name('logout');
        });
    });


// Versioned compatibility surface for independently maintained native clients.
// The legacy /api/mobile endpoints remain the canonical implementation.
Route::middleware(['api', 'installed', 'plugin:mobile-app-api'])
    ->prefix('api/v1')
    ->name('plugins.mobile.v1.')
    ->group(function (): void {
        Route::get('/config', [MobileApiController::class, 'config'])->name('config');
        Route::get('/home', [MobileApiController::class, 'home'])->name('home');
        Route::post('/auth/register', [MobileApiController::class, 'register'])
            ->middleware('throttle:5,1')
            ->name('auth.register');
        Route::post('/auth/login', [MobileApiController::class, 'login'])
            ->middleware('throttle:10,1')
            ->name('auth.login');
        Route::get('/courses', [MobileApiController::class, 'courses'])->name('courses');
        Route::get('/courses/{slug}', [MobileApiController::class, 'courseShow'])->name('courses.show');
        Route::get('/ebooks', [MobileApiController::class, 'ebooks'])->name('ebooks');
        Route::get('/products', [MobileApiController::class, 'products'])->name('products');
        Route::get('/exams', [MobileApiController::class, 'exams'])->name('exams');
        Route::get('/notices', [MobileApiController::class, 'notices'])->name('notices');
        Route::get('/certificates/verify/{code}', [MobileApiController::class, 'verifyCertificate'])->name('certificates.verify');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('/auth/me', [MobileApiController::class, 'me'])->name('auth.me');
            Route::post('/auth/logout', [MobileApiController::class, 'logout'])->name('auth.logout');
            Route::post('/push-token', [MobileApiController::class, 'registerPushToken'])->middleware('throttle:30,1')->name('push_token');
            Route::get('/courses/{slug}/lessons', [MobileApiController::class, 'lessons'])->name('courses.lessons');
            Route::get('/instructor/courses', [InstructorMobileApiController::class, 'courses'])->name('instructor.courses');
            Route::get('/instructor/courses/{slug}/students', [InstructorMobileApiController::class, 'courseStudents'])->name('instructor.courses.students');
            Route::get('/instructor/batches', [InstructorMobileApiController::class, 'batches'])->name('instructor.batches');
            Route::get('/instructor/batches/{batch}', [InstructorMobileApiController::class, 'showBatch'])->whereNumber('batch')->name('instructor.batches.show');
            Route::get('/instructor/assignments', [InstructorMobileApiController::class, 'assignments'])->name('instructor.assignments');
            Route::post('/instructor/batches/{batch}/assignments', [InstructorMobileApiController::class, 'storeAssignment'])->whereNumber('batch')->middleware('throttle:10,1')->name('instructor.assignments.store');
            Route::get('/instructor/assignments/{assignment}/submissions', [InstructorMobileApiController::class, 'submissions'])->whereNumber('assignment')->name('instructor.assignments.submissions');
            Route::patch('/instructor/assignments/{assignment}/submissions/{submission}', [InstructorMobileApiController::class, 'grade'])->whereNumber('assignment')->whereNumber('submission')->middleware('throttle:20,1')->name('instructor.assignments.grade');
            Route::get('/instructor/exams', [InstructorMobileApiController::class, 'exams'])->name('instructor.exams');
            Route::get('/instructor/exams/{exam}/results', [InstructorMobileApiController::class, 'examResults'])->whereNumber('exam')->name('instructor.exams.results');
            Route::get('/instructor/courses/{slug}/lessons', [InstructorMobileApiController::class, 'lessons'])->name('instructor.lessons');
            Route::post('/instructor/courses/{slug}/lessons', [InstructorMobileApiController::class, 'storeLesson'])->middleware('throttle:20,1')->name('instructor.lessons.store');
            Route::put('/instructor/courses/{slug}/lessons/{lesson}', [InstructorMobileApiController::class, 'updateLesson'])->whereNumber('lesson')->middleware('throttle:30,1')->name('instructor.lessons.update');
            Route::delete('/instructor/courses/{slug}/lessons/{lesson}', [InstructorMobileApiController::class, 'destroyLesson'])->whereNumber('lesson')->middleware('throttle:10,1')->name('instructor.lessons.destroy');
            Route::get('/instructor/attendance/policy', [InstructorMobileApiController::class, 'attendancePolicy'])->name('instructor.attendance.policy');
            Route::post('/instructor/batches/{batch}/attendance/qr', [InstructorMobileApiController::class, 'createAttendanceQr'])->whereNumber('batch')->middleware('throttle:10,1')->name('instructor.attendance.qr');
            Route::get('/instructor/notices', [InstructorMobileApiController::class, 'notices'])->name('instructor.notices');
            Route::post('/instructor/notices/{notice}/read', [InstructorMobileApiController::class, 'markNoticeRead'])->whereNumber('notice')->middleware('throttle:60,1')->name('instructor.notices.read');
            Route::get('/instructor/live-classes', [InstructorMobileApiController::class, 'liveClasses'])->name('instructor.live-classes');
            Route::post('/instructor/batches/{batch}/live-classes', [InstructorMobileApiController::class, 'storeLiveClass'])->whereNumber('batch')->middleware('throttle:10,1')->name('instructor.live-classes.store');
            Route::patch('/instructor/live-classes/{liveClass}', [InstructorMobileApiController::class, 'updateLiveClass'])->whereNumber('liveClass')->middleware('throttle:20,1')->name('instructor.live-classes.update');
        });
    });
