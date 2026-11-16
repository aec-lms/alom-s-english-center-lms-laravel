<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PluginCenterController;
use App\Http\Controllers\Admin\UpdateCenterController;
use App\Http\Controllers\Admin\UserRoleController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\InstallerController;
use App\Http\Controllers\PublicHomeController;
use App\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

// Browser-based Fresh Installer (permanently locked after installation)
Route::prefix('install')->name('installer.')->middleware('not.installed')->group(function (): void {
    Route::get('/', [InstallerController::class, 'index'])->name('index');
    Route::post('/', [InstallerController::class, 'store'])->name('store');
});

Route::middleware('installed')->group(function (): void {
    Route::get('/', [PublicHomeController::class, 'index'])->name('home');
    Route::get('/courses', [PublicHomeController::class, 'courses'])->name('public.courses');
    Route::get('/course/{slug}', [PublicHomeController::class, 'courseShow'])->name('public.courses.show');
    Route::post('/courses/coupon-check', [PublicHomeController::class, 'checkCoupon'])->name('public.courses.coupon');
    Route::get('/learn', [PublicHomeController::class, 'learn'])->name('public.learn');
    Route::get('/instructors', [PublicHomeController::class, 'instructors'])->name('public.instructors');
    Route::get('/teacher/{id}', [PublicHomeController::class, 'instructorShow'])->name('public.instructors.show');
    Route::get('/exams', [PublicHomeController::class, 'exams'])->name('public.exams');
    Route::get('/products', [PublicHomeController::class, 'products'])->name('public.products');
    Route::get('/product/{slug}', [PublicHomeController::class, 'productShow'])->name('public.products.show');
    Route::get('/ebooks', [PublicHomeController::class, 'ebooks'])->name('public.ebooks');
    Route::get('/ebooks/{slug}', [PublicHomeController::class, 'ebookShow'])->name('public.ebooks.show');
    Route::get('/result', [PublicHomeController::class, 'result'])->name('public.result');
    Route::get('/board-result', [PublicHomeController::class, 'result'])->name('public.board_result');
    Route::get('/page/{slug}', [PublicHomeController::class, 'customPage'])->name('public.custom_page');

    Route::get('/about-us', [PublicHomeController::class, 'aboutUs'])->name('public.about');
    Route::get('/contact-us', [PublicHomeController::class, 'contactUs'])->name('public.contact');
    Route::post('/contact-us', [PublicHomeController::class, 'submitContact'])->name('public.contact.submit');
    Route::get('/terms-and-conditions', [PublicHomeController::class, 'legalPage'])->defaults('slug', 'terms-and-conditions')->name('public.terms');
    Route::get('/privacy-policy', [PublicHomeController::class, 'legalPage'])->defaults('slug', 'privacy-policy')->name('public.privacy');
    Route::get('/refund-policy', [PublicHomeController::class, 'legalPage'])->defaults('slug', 'refund-policy')->name('public.refund');
    Route::get('/profile', [PublicHomeController::class, 'profile'])->name('public.profile' );
    Route::get('/dashboard', [PortalController::class, 'index'])->middleware('auth')->name('portal.dashboard');

    Route::get('/login', [LoginController::class, 'show'])->name('login')->middleware('guest');
    Route::post('/login', [LoginController::class, 'authenticate'])->name('login.attempt')->middleware('guest');
    Route::get('/register', [LoginController::class, 'showRegister'])->name('register')->middleware('guest');
    Route::post('/register', [LoginController::class, 'register'])->name('register.attempt')->middleware('guest');
    Route::get('/forgot-password', [LoginController::class, 'showForgotPassword'])->name('password.request')->middleware('guest');
    Route::post('/forgot-password', [LoginController::class, 'sendResetNotice'])->name('password.email')->middleware('guest');
    Route::get('/reset-password/{token}', [LoginController::class, 'showResetPassword'])->name('password.reset')->middleware('guest');
    Route::post('/reset-password', [LoginController::class, 'resetPassword'])->name('password.update')->middleware('guest');
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

    Route::prefix('admin')->name('admin.')->middleware('auth')->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])
            ->name('dashboard')
            ->middleware('permission:core.dashboard.view');

        Route::prefix('plugins')->name('plugins.')->middleware('permission:core.plugins.manage')->group(function (): void {
            Route::get('/', [PluginCenterController::class, 'index'])->name('index');
            Route::post('/upload', [PluginCenterController::class, 'upload'])->name('upload');
            Route::post('/{slug}/install', [PluginCenterController::class, 'install'])->name('install');
            Route::post('/{slug}/update', [PluginCenterController::class, 'update'])->name('update');
            Route::post('/{slug}/enable', [PluginCenterController::class, 'enable'])->name('enable');
            Route::post('/{slug}/disable', [PluginCenterController::class, 'disable'])->name('disable');
            Route::post('/{slug}/uninstall', [PluginCenterController::class, 'uninstall'])->name('uninstall');
            Route::get('/{slug}/configure', [PluginCenterController::class, 'configure'])->name('configure');
            Route::post('/{slug}/configure', [PluginCenterController::class, 'saveConfiguration'])->name('configure.save');
            Route::post('/{slug}/test-connection', [PluginCenterController::class, 'testConnection'])->name('test-connection');
        });

        Route::prefix('updates')->name('updates.')->middleware('permission:core.updates.manage')->group(function (): void {
            Route::get('/', [UpdateCenterController::class, 'index'])->name('index');
            Route::post('/', [UpdateCenterController::class, 'apply'])->name('apply');
            Route::post('/feed', [UpdateCenterController::class, 'saveFeed'])->name('feed');
            Route::post('/check', [UpdateCenterController::class, 'checkFeed'])->name('check');
            Route::post('/{log}/rollback', [UpdateCenterController::class, 'rollback'])->name('rollback');
        });

        Route::prefix('backups')->name('backups.')->middleware('permission:core.backups.manage')->group(function (): void {
            Route::get('/', [BackupController::class, 'index'])->name('index');
            Route::post('/', [BackupController::class, 'store'])->name('store');
            Route::post('/{backup}/restore', [BackupController::class, 'restore'])->name('restore');
        });

        Route::prefix('users')->name('users.')->middleware('permission:core.users.manage')->group(function (): void {
            Route::get('/', [UserRoleController::class, 'index'])->name('index');
            Route::post('/', [UserRoleController::class, 'store'])->name('store');
        });

        Route::get('/audit-logs', [AuditLogController::class, 'index'])
            ->name('audit.index')
            ->middleware('permission:core.audit.view');
    });
});
