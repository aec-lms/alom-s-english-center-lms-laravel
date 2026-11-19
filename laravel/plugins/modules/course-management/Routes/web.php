<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\CourseManagement\Http\Controllers\CourseController;
use Plugins\Modules\CourseManagement\Http\Controllers\CourseTeacherAssignmentController;
use Plugins\Modules\CourseManagement\Http\Controllers\LessonContentProtectionController;
use Plugins\Modules\CourseManagement\Http\Controllers\LiveClassAccessController;

Route::middleware(['web', 'installed', 'auth', 'plugin:course-management', 'permission:courses.manage'])
    ->prefix('admin/courses')
    ->name('plugins.courses.')
    ->group(function (): void {
        Route::get('/', [CourseController::class, 'index'])->name('index');
        Route::get('/teacher-assignments', [CourseTeacherAssignmentController::class, 'index'])->name('teachers.index');
        Route::post('/teacher-assignments', [CourseTeacherAssignmentController::class, 'store'])->name('teachers.store');
        Route::delete('/teacher-assignments/{assignment}', [CourseTeacherAssignmentController::class, 'destroy'])->whereNumber('assignment')->name('teachers.destroy');
        Route::get('/content-protection', [LessonContentProtectionController::class, 'adminIndex'])->name('content-protection');
        Route::post('/', [CourseController::class, 'store'])->name('store');
        Route::put('/{course}', [CourseController::class, 'update'])->name('update');
        Route::delete('/{course}', [CourseController::class, 'destroy'])->name('destroy');

        Route::post('/lessons/{lesson}/media', [LessonContentProtectionController::class, 'upload'])->name('lessons.media.upload');
        Route::patch('/lessons/{lesson}/protection', [LessonContentProtectionController::class, 'updatePolicy'])->name('lessons.protection.update');

        // Categories CRUD
        Route::post('/categories', [CourseController::class, 'storeCategory'])->name('categories.store');
        Route::put('/categories/{category}', [CourseController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{category}', [CourseController::class, 'destroyCategory'])->name('categories.destroy');

        // Live Classes CRUD
        Route::post('/live-classes', [CourseController::class, 'storeLiveClass'])->name('live.store');
        Route::put('/live-classes/{liveClass}', [CourseController::class, 'updateLiveClass'])->name('live.update');
        Route::delete('/live-classes/{liveClass}', [CourseController::class, 'destroyLiveClass'])->name('live.destroy');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:course-management'])
    ->prefix('live-classes/{liveClass}')
    ->name('plugins.live-classes.')
    ->group(function (): void {
        Route::post('/join', [LiveClassAccessController::class, 'join'])
            ->middleware('throttle:10,1')->name('join');
        Route::post('/heartbeat', [LiveClassAccessController::class, 'heartbeat'])
            ->middleware('throttle:6,1')->name('heartbeat');
        Route::post('/leave', [LiveClassAccessController::class, 'leave'])
            ->middleware('throttle:10,1')->name('leave');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:course-management'])->group(function (): void {
    Route::get('/my-learning/enrollments', [\Plugins\Modules\CourseManagement\Http\Controllers\EnrollmentController::class, 'my'])
        ->name('plugins.enrollments.my');
    Route::post('/courses/{course}/enroll', [\Plugins\Modules\CourseManagement\Http\Controllers\EnrollmentController::class, 'store'])
        ->name('plugins.enrollments.store');

    Route::get('/my-learning/lessons/{lesson}/media-access', [LessonContentProtectionController::class, 'issueAccess'])
        ->name('plugins.lesson-media.access')->middleware('throttle:30,1');
    Route::get('/my-learning/lessons/{lesson}/media/{user}', [LessonContentProtectionController::class, 'stream'])
        ->name('plugins.lesson-media.stream')->middleware(['signed', 'throttle:60,1']);
    Route::get('/my-learning/lessons/{lesson}/download/{user}', [LessonContentProtectionController::class, 'download'])
        ->name('plugins.lesson-media.download')->middleware(['signed', 'throttle:10,1']);
    Route::get('/my-learning/courses/{course}/lessons', [LessonContentProtectionController::class, 'showCourse'])
        ->name('plugins.lessons.course');
});

Route::middleware(['web', 'installed', 'auth', 'plugin:course-management', 'permission:enrollments.manage'])
    ->prefix('admin/enrollments')
    ->name('plugins.enrollments.')
    ->group(function (): void {
        Route::get('/', [\Plugins\Modules\CourseManagement\Http\Controllers\EnrollmentController::class, 'index'])->name('index');
        Route::patch('/{enrollment}/status', [\Plugins\Modules\CourseManagement\Http\Controllers\EnrollmentController::class, 'updateStatus'])->name('status');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:course-management'])
    ->prefix('learning-interactions')
    ->name('plugins.learning-interactions.')
    ->group(function (): void {
        Route::get('/lessons/{lesson}/comments', [\Plugins\Modules\CourseManagement\Http\Controllers\LearningInteractionController::class, 'lessonComments'])
            ->whereNumber('lesson')->middleware('throttle:60,1')->name('lesson.comments.index');
        Route::post('/lessons/{lesson}/comments', [\Plugins\Modules\CourseManagement\Http\Controllers\LearningInteractionController::class, 'storeLessonComment'])
            ->whereNumber('lesson')->middleware('throttle:12,1')->name('lesson.comments.store');
        Route::patch('/lessons/{lesson}/comments/{comment}', [\Plugins\Modules\CourseManagement\Http\Controllers\LearningInteractionController::class, 'updateLessonComment'])
            ->whereNumber('lesson')->whereNumber('comment')->middleware('throttle:20,1')->name('lesson.comments.update');
        Route::delete('/lessons/{lesson}/comments/{comment}', [\Plugins\Modules\CourseManagement\Http\Controllers\LearningInteractionController::class, 'deleteLessonComment'])
            ->whereNumber('lesson')->whereNumber('comment')->middleware('throttle:20,1')->name('lesson.comments.destroy');

        Route::get('/live-classes/{liveClass}/questions', [\Plugins\Modules\CourseManagement\Http\Controllers\LearningInteractionController::class, 'liveQuestions'])
            ->whereNumber('liveClass')->middleware('throttle:60,1')->name('live.questions.index');
        Route::post('/live-classes/{liveClass}/questions', [\Plugins\Modules\CourseManagement\Http\Controllers\LearningInteractionController::class, 'askLiveQuestion'])
            ->whereNumber('liveClass')->middleware('throttle:10,1')->name('live.questions.store');
        Route::post('/live-classes/{liveClass}/questions/{question}/answers', [\Plugins\Modules\CourseManagement\Http\Controllers\LearningInteractionController::class, 'answerLiveQuestion'])
            ->whereNumber('liveClass')->whereNumber('question')->middleware('throttle:20,1')->name('live.questions.answer');
        Route::patch('/live-classes/{liveClass}/questions/{question}', [\Plugins\Modules\CourseManagement\Http\Controllers\LearningInteractionController::class, 'moderateLiveQuestion'])
            ->whereNumber('liveClass')->whereNumber('question')->middleware('throttle:20,1')->name('live.questions.moderate');
        Route::post('/live-classes/{liveClass}/questions/{question}/vote', [\Plugins\Modules\CourseManagement\Http\Controllers\LearningInteractionController::class, 'voteLiveQuestion'])
            ->whereNumber('liveClass')->whereNumber('question')->middleware('throttle:30,1')->name('live.questions.vote');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:course-management'])
    ->prefix('student/course-commerce')
    ->name('course-commerce.')
    ->group(function (): void {
        Route::get('/cart', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'cart'])->name('cart');
        Route::get('/gifts/sent', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'sentGifts'])->name('gifts.sent');
        Route::post('/cart/items/{course}', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'add'])->whereNumber('course')->name('cart.add');
        Route::delete('/cart/items/{item}', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'remove'])->whereNumber('item')->name('cart.remove');
        Route::post('/checkout', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'checkout'])->middleware('throttle:10,1')->name('checkout');
        Route::get('/gifts/redeem/{token}', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'giftRedeemForm'])->where('token', '[A-Za-z0-9]{40,80}')->name('gifts.redeem-form');
        Route::post('/gifts/redeem/{token}', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'redeemGift'])->where('token', '[A-Za-z0-9]{40,80}')->middleware('throttle:5,1')->name('gifts.redeem');
        Route::get('/vouchers/buy', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'voucherForm'])->name('vouchers.form');
        Route::post('/vouchers/buy', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'purchaseVoucher'])->middleware('throttle:5,1')->name('vouchers.purchase');
        Route::get('/vouchers/redeem/{token}', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'voucherRedeemForm'])->where('token', '[A-Za-z0-9]{40,80}')->name('vouchers.redeem-form');
        Route::post('/vouchers/redeem/{token}', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'redeemVoucher'])->where('token', '[A-Za-z0-9]{40,80}')->middleware('throttle:5,1')->name('vouchers.redeem');
        Route::post('/gifts/{gift}/resend', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'resendCourseGiftEmail'])->whereNumber('gift')->middleware('throttle:2,10')->name('gifts.resend');
        Route::post('/vouchers/{voucher}/resend', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'resendGiftVoucherEmail'])->whereNumber('voucher')->middleware('throttle:2,10')->name('vouchers.resend');
        Route::get('/gifts/{course}/send', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'giftForm'])->whereNumber('course')->name('gifts.form');
        Route::post('/gifts/{course}', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'gift'])->whereNumber('course')->middleware('throttle:5,1')->name('gifts.create');
    });
