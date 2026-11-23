<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AppearanceController;
use App\Http\Controllers\Admin\ThemePackageController;
use App\Http\Controllers\Admin\SiteIdentityController;
use App\Http\Controllers\Admin\SiteLayoutController;
use App\Http\Controllers\Admin\SiteWidgetController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DeviceSessionController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\AutomationSaasController;
use App\Http\Controllers\Admin\SetupWizardController;
use App\Http\Controllers\Admin\RolePermissionController;
use App\Http\Controllers\Admin\AdminMenuController;
use App\Http\Controllers\Admin\SiteNavigationController;
use App\Http\Controllers\Admin\InstitutionSettingsController;
use App\Http\Controllers\Admin\CourseSocialProofSettingsController;
use App\Http\Controllers\Admin\HealthMonitorController;
use App\Http\Controllers\Admin\RecycleBinController;
use App\Http\Controllers\Admin\PluginCenterController;
use App\Http\Controllers\Admin\UpdateCenterController;
use App\Http\Controllers\Admin\UserRoleController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\InstallerController;
use App\Http\Controllers\PublicHomeController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ProfileSettingsController;
use Illuminate\Support\Facades\Route;

// Browser-based Fresh Installer (permanently locked after installation)
Route::prefix('install')->name('installer.')->middleware('not.installed')->group(function (): void {
    Route::get('/', [InstallerController::class, 'index'])->name('index');
    Route::post('/', [InstallerController::class, 'store'])->name('store');
});

Route::middleware('installed')->group(function (): void {
    Route::get('/', [PublicHomeController::class, 'index'])->name('home');
    Route::post('/language/{locale}', [LanguageController::class, 'switch'])
        ->where('locale', 'en|bn')->middleware('throttle:30,1')->name('language.switch');
    Route::get('/courses', [PublicHomeController::class, 'courses'])->name('public.courses');
    Route::get('/course/{slug}', [PublicHomeController::class, 'courseShow'])->name('public.courses.show');
    Route::post('/courses/coupon-check', [PublicHomeController::class, 'checkCoupon'])->middleware('throttle:public-coupon-check')->name('public.courses.coupon');
    Route::get('/learn', [PublicHomeController::class, 'learn'])->name('public.learn');
    Route::get('/learn/{slug}', [PublicHomeController::class, 'learnShow'])->name('public.learn.show');
    Route::get('/instructors', [PublicHomeController::class, 'instructors'])->name('public.instructors');
    Route::get('/teacher/{id}', [PublicHomeController::class, 'instructorShow'])->name('public.instructors.show');
    Route::get('/exams', [PublicHomeController::class, 'exams'])->name('public.exams');
    Route::get('/products', [PublicHomeController::class, 'products'])->name('public.products');
    Route::get('/product/{slug}', [PublicHomeController::class, 'productShow'])->name('public.products.show');
    Route::get('/ebooks', [PublicHomeController::class, 'ebooks'])->name('public.ebooks');
    Route::get('/ebooks/{slug}', [PublicHomeController::class, 'ebookShow'])->name('public.ebooks.show');
    Route::get('/ebooks/{slug}/download', [PublicHomeController::class, 'ebookDownload'])->name('public.ebooks.download');
    Route::get('/result', [PublicHomeController::class, 'result'])->name('public.result');
    Route::get('/board-result', [PublicHomeController::class, 'result'])->name('public.board_result');
    Route::get('/page/{slug}', [PublicHomeController::class, 'customPage'])->name('public.custom_page');

    Route::get('/about-us', [PublicHomeController::class, 'aboutUs'])->name('public.about');
    Route::get('/contact-us', [PublicHomeController::class, 'contactUs'])->name('public.contact');
    Route::post('/contact-us', [PublicHomeController::class, 'submitContact'])->middleware('throttle:public-contact')->name('public.contact.submit');
    Route::get('/terms-and-conditions', [PublicHomeController::class, 'legalPage'])->defaults('slug', 'terms-and-conditions')->name('public.terms');
    Route::get('/privacy-policy', [PublicHomeController::class, 'legalPage'])->defaults('slug', 'privacy-policy')->name('public.privacy');
    Route::get('/refund-policy', [PublicHomeController::class, 'legalPage'])->defaults('slug', 'refund-policy')->name('public.refund');
    Route::get('/profile', [PublicHomeController::class, 'profile'])->name('public.profile' );
    Route::get('/dashboard', [PortalController::class, 'index'])->middleware('auth')->name('portal.dashboard');
    Route::get('/u/{username}', [ProfileSettingsController::class, 'showPublic'])->where('username', '[A-Za-z0-9_]{3,40}')->name('profile.public');
    Route::get('/u/{username}/media/{type}', [ProfileSettingsController::class, 'media'])->where(['username' => '[A-Za-z0-9_]{3,40}', 'type' => 'avatar|cover'])->name('profile.media');
    Route::middleware('auth')->prefix('profile/settings')->name('profile.settings.')->group(function (): void {
        Route::get('/', [ProfileSettingsController::class, 'edit'])->name('edit');
        Route::get('/activity', [ProfileSettingsController::class, 'activity'])->name('activity');
        Route::get('/media/{type}', [ProfileSettingsController::class, 'myMedia'])->where('type', 'avatar|cover')->name('media');
        Route::put('/', [ProfileSettingsController::class, 'update'])->name('update');
    });

    Route::get('/login', [LoginController::class, 'show'])->name('login')->middleware('guest');
    Route::post('/login', [LoginController::class, 'authenticate'])->name('login.attempt')->middleware(['guest', 'throttle:auth-login']);
    Route::get('/register', [LoginController::class, 'showRegister'])->name('register')->middleware('guest');
    Route::post('/register', [LoginController::class, 'register'])->name('register.attempt')->middleware(['guest', 'throttle:auth-register']);
    Route::get('/forgot-password', [LoginController::class, 'showForgotPassword'])->name('password.request')->middleware('guest');
    Route::post('/forgot-password', [LoginController::class, 'sendResetNotice'])->name('password.email')->middleware(['guest', 'throttle:password-reset-email']);
    Route::post('/forgot-password/otp', [LoginController::class, 'sendRecoveryOtp'])->name('password.otp.send')->middleware(['guest', 'throttle:password-otp-send']);
    Route::post('/forgot-password/otp/verify', [LoginController::class, 'verifyRecoveryOtp'])->name('password.otp.verify')->middleware(['guest', 'throttle:password-otp-verify']);
    Route::get('/reset-password/{token}', [LoginController::class, 'showResetPassword'])->name('password.reset')->middleware('guest');
    Route::post('/reset-password', [LoginController::class, 'resetPassword'])->name('password.update')->middleware(['guest', 'throttle:password-reset-submit']);
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

    Route::prefix('admin')->name('admin.')->middleware('auth')->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])
            ->name('dashboard')
            ->middleware('permission:core.dashboard.view');
        Route::post('/dashboard/widgets', [DashboardController::class, 'saveWidgets'])
            ->name('dashboard.widgets')
            ->middleware('permission:core.dashboard.view');
        Route::get('/dashboard/refresh', [DashboardController::class, 'refresh'])
            ->name('dashboard.refresh')
            ->middleware(['permission:core.dashboard.view', 'throttle:60,1']);
        Route::post('/dashboard/widget-analytics', [DashboardController::class, 'trackWidget'])
            ->name('dashboard.widget-analytics')
            ->middleware(['permission:core.dashboard.view', 'throttle:30,1']);
        Route::post('/dashboard/motion', [DashboardController::class, 'saveMotionSettings'])
            ->name('dashboard.motion')
            ->middleware(['permission:core.settings.manage', 'central.admin']);
        Route::prefix('setup-wizard')->name('setup-wizard.')->middleware(['permission:core.settings.manage', 'central.admin'])->group(function (): void {
            Route::get('/', [SetupWizardController::class, 'index'])->name('index');
            Route::post('/complete', [SetupWizardController::class, 'complete'])->name('complete');
            Route::post('/{step}', [SetupWizardController::class, 'save'])->name('save');
        });
        Route::get('/menu-editor', [AdminMenuController::class, 'index'])
            ->name('menu-editor.index')
            ->middleware(['permission:core.settings.manage', 'central.admin']);
        Route::post('/menu-editor', [AdminMenuController::class, 'update'])
            ->name('menu-editor.update')
            ->middleware(['permission:core.settings.manage', 'central.admin']);

        Route::prefix('site-navigation')->name('site-navigation.')->middleware(['permission:core.settings.manage', 'central.admin'])->group(function (): void {
            Route::get('/', [SiteNavigationController::class, 'index'])->name('index');
            Route::post('/', [SiteNavigationController::class, 'update'])->name('update');
        });

        Route::prefix('automation-saas')->name('automation-saas.')->middleware('permission:core.settings.manage')->group(function (): void {
            Route::get('/', [AutomationSaasController::class, 'index'])->name('index');
            Route::post('/tenants', [AutomationSaasController::class, 'storeTenant'])->name('tenants.store');
            Route::post('/tenants/{tenant}/agents', [AutomationSaasController::class, 'storeAgent'])->name('agents.store');
            Route::post('/tenants/{tenant}/contacts', [AutomationSaasController::class, 'storeContact'])->name('contacts.store');
            Route::post('/tenants/{tenant}/campaigns', [AutomationSaasController::class, 'storeCampaign'])->name('campaigns.store');
            Route::post('/tenants/{tenant}/workflows', [AutomationSaasController::class, 'storeWorkflow'])->name('workflows.store');
            Route::post('/tenants/{tenant}/workflows/{workflow}/publish', [AutomationSaasController::class, 'publishWorkflow'])->name('workflows.publish');
        });

        Route::prefix('plugins')->name('plugins.')->middleware(['permission:core.plugins.manage', 'central.admin'])->group(function (): void {
            Route::get('/', [PluginCenterController::class, 'index'])->name('index');
            Route::get('/{slug}/favicon.svg', [PluginCenterController::class, 'favicon'])->where('slug', '[a-z0-9-]+')->name('favicon');
            Route::post('/upload', [PluginCenterController::class, 'upload'])->name('upload');
            Route::post('/{slug}/install', [PluginCenterController::class, 'install'])->name('install');
            Route::post('/{slug}/update', [PluginCenterController::class, 'update'])->name('update');
            Route::post('/{slug}/enable', [PluginCenterController::class, 'enable'])->name('enable');
            Route::post('/{slug}/disable', [PluginCenterController::class, 'disable'])->name('disable');
            Route::post('/{slug}/reset-settings', [PluginCenterController::class, 'resetSettings'])->name('reset-settings');
            Route::post('/{slug}/delete', [PluginCenterController::class, 'deleteToRecycleBin'])->name('delete');
            Route::post('/recycle-bin/{archive}/restore', [PluginCenterController::class, 'restoreFromRecycleBin'])->name('recycle-bin.restore');
            Route::post('/recycle-bin/{archive}/permanent-delete', [PluginCenterController::class, 'permanentlyDeleteFromRecycleBin'])->name('recycle-bin.permanent-delete');
            Route::post('/{slug}/uninstall', [PluginCenterController::class, 'uninstall'])->name('uninstall');
            Route::get('/{slug}/configure', [PluginCenterController::class, 'configure'])->name('configure');
            Route::post('/{slug}/configure', [PluginCenterController::class, 'saveConfiguration'])->name('configure.save');
            Route::post('/{slug}/test-connection', [PluginCenterController::class, 'testConnection'])->name('test-connection');
        });

        Route::prefix('updates')->name('updates.')->middleware(['permission:core.updates.manage', 'central.admin'])->group(function (): void {
            Route::get('/', [UpdateCenterController::class, 'index'])->name('index');
            Route::post('/', [UpdateCenterController::class, 'apply'])->name('apply');
            Route::post('/feed', [UpdateCenterController::class, 'saveFeed'])->name('feed');
            Route::post('/check', [UpdateCenterController::class, 'checkFeed'])->name('check');
            Route::post('/{log}/rollback', [UpdateCenterController::class, 'rollback'])->name('rollback');
        });

        Route::get('/course-social-proof', [CourseSocialProofSettingsController::class, 'edit'])
            ->name('course-social-proof.edit')
            ->middleware(['permission:core.settings.manage', 'central.admin']);
        Route::post('/course-social-proof', [CourseSocialProofSettingsController::class, 'update'])
            ->name('course-social-proof.update')
            ->middleware(['permission:core.settings.manage', 'central.admin']);

        Route::get('/health', [HealthMonitorController::class, 'index'])->name('health.index')->middleware(['permission:core.dashboard.view', 'central.admin']);
        Route::post('/health/check', [HealthMonitorController::class, 'check'])->name('health.check')->middleware(['permission:core.dashboard.view', 'central.admin']);

        Route::prefix('backups')->name('backups.')->middleware(['permission:core.backups.manage', 'central.admin'])->group(function (): void {
            Route::get('/', [BackupController::class, 'index'])->name('index');
            Route::post('/', [BackupController::class, 'store'])->name('store');
            Route::post('/{backup}/restore', [BackupController::class, 'restore'])->name('restore');
        });

        Route::prefix('roles')->name('roles.')->middleware(['permission:core.users.manage', 'central.admin'])->group(function (): void {
            Route::get('/', [RolePermissionController::class, 'index'])->name('index');
            Route::post('/', [RolePermissionController::class, 'store'])->name('store');
            Route::put('/{role}/permissions', [RolePermissionController::class, 'update'])->name('permissions.update');
        });

        Route::prefix('users')->name('users.')->middleware(['permission:core.users.manage', 'central.admin'])->group(function (): void {
            Route::get('/', [UserRoleController::class, 'index'])->name('index');
            Route::post('/', [UserRoleController::class, 'store'])->name('store');
        });

        Route::prefix('institution-settings')->name('institution-settings.')->middleware(['permission:core.settings.manage', 'central.admin'])->group(function (): void {
            Route::get('/', [InstitutionSettingsController::class, 'edit'])->name('edit');
            Route::post('/', [InstitutionSettingsController::class, 'update'])->name('update');
        });

        Route::prefix('site-identity')->name('site-identity.')->middleware(['permission:core.settings.manage', 'central.admin'])->group(function (): void {
            Route::get('/', [SiteIdentityController::class, 'edit'])->name('edit');
            Route::post('/', [SiteIdentityController::class, 'update'])->name('update');
        });

        Route::prefix('site-layout')->name('site-layout.')->middleware(['permission:core.settings.manage', 'central.admin'])->group(function (): void {
            Route::get('/', [SiteLayoutController::class, 'index'])->name('index');
            Route::post('/', [SiteLayoutController::class, 'update'])->name('update');
        });

        Route::prefix('site-widgets')->name('site-widgets.')->middleware(['permission:core.settings.manage', 'central.admin'])->group(function (): void {
            Route::get('/', [SiteWidgetController::class, 'index'])->name('index');
            Route::post('/', [SiteWidgetController::class, 'update'])->name('update');
        });

        Route::prefix('appearance')->name('appearance.')->middleware(['permission:core.settings.manage', 'central.admin'])->group(function (): void {
            Route::get('/', [AppearanceController::class, 'index'])->name('index');
            Route::post('/', [AppearanceController::class, 'update'])->name('update');
            Route::post('/design', [AppearanceController::class, 'updateDesign'])->name('design');
            Route::post('/reset', [AppearanceController::class, 'reset'])->name('reset');
            Route::get('/themes', [ThemePackageController::class, 'index'])->name('themes.index');
            Route::get('/themes/{slug}/preview', [ThemePackageController::class, 'preview'])->name('themes.preview');
            Route::post('/themes/upload', [ThemePackageController::class, 'upload'])->name('themes.upload');
            Route::post('/themes/{slug}/activate', [ThemePackageController::class, 'activate'])->name('themes.activate');
            Route::post('/themes/deactivate', [ThemePackageController::class, 'deactivate'])->name('themes.deactivate');
            Route::delete('/themes/{slug}', [ThemePackageController::class, 'uninstall'])->name('themes.uninstall');
        });

        Route::prefix('recycle-bin')->name('recycle-bin.')->middleware(['permission:core.recycle_bin.manage', 'central.admin'])->group(function (): void {
            Route::get('/', [RecycleBinController::class, 'index'])->name('index');
            Route::post('/{type}/{recordId}/permanent-delete', [RecycleBinController::class, 'permanentlyDelete'])->whereNumber('recordId')->name('permanent-delete');
            Route::post('/students/{student}/restore', [RecycleBinController::class, 'restoreStudent'])->name('students.restore');
            Route::post('/teachers/{teacher}/restore', [RecycleBinController::class, 'restoreTeacher'])->name('teachers.restore');
            Route::post('/courses/{recordId}/restore', [RecycleBinController::class, 'restoreCourse'])->name('courses.restore');
            Route::post('/course-categories/{recordId}/restore', [RecycleBinController::class, 'restoreCourseCategory'])->name('course-categories.restore');
            Route::post('/live-classes/{recordId}/restore', [RecycleBinController::class, 'restoreLiveClass'])->name('live-classes.restore');
            Route::post('/exams/{recordId}/restore', [RecycleBinController::class, 'restoreExam'])->name('exams.restore');
            Route::post('/batches/{recordId}/restore', [RecycleBinController::class, 'restoreBatch'])->name('batches.restore');
            Route::post('/classes/{recordId}/restore', [RecycleBinController::class, 'restoreClass'])->name('classes.restore');
            Route::post('/boost-campaigns/{recordId}/restore', [RecycleBinController::class, 'restoreBoostCampaign'])->name('boost-campaigns.restore');
            Route::post('/branches/{recordId}/restore', [RecycleBinController::class, 'restoreBranch'])->name('branches.restore');
            Route::post('/carousel-items/{recordId}/restore', [RecycleBinController::class, 'restoreCarouselItem'])->name('carousel-items.restore');
            Route::post('/cms-categories/{recordId}/restore', [RecycleBinController::class, 'restoreCmsCategory'])->name('cms-categories.restore');
            Route::post('/cms-posts/{recordId}/restore', [RecycleBinController::class, 'restoreCmsPost'])->name('cms-posts.restore');
            Route::post('/builder-pages/{recordId}/restore', [RecycleBinController::class, 'restoreBuilderPage'])->name('builder-pages.restore');
            Route::post('/hero-sliders/{recordId}/restore', [RecycleBinController::class, 'restoreHeroSlider'])->name('hero-sliders.restore');
            Route::post('/sms-campaigns/{recordId}/restore', [RecycleBinController::class, 'restoreSmsCampaign'])->name('sms-campaigns.restore');
            Route::post('/visitor-popups/{recordId}/restore', [RecycleBinController::class, 'restoreVisitorPopup'])->name('visitor-popups.restore');
        });

        Route::get('/audit-logs', [AuditLogController::class, 'index'])
            ->name('audit.index')
            ->middleware(['permission:core.audit.view', 'central.admin']);

        Route::prefix('security/sessions')->name('security.sessions.')->middleware(['permission:core.audit.view', 'central.admin'])->group(function (): void {
            Route::get('/', [DeviceSessionController::class, 'index'])->name('index');
            Route::patch('/{deviceSession}/revoke', [DeviceSessionController::class, 'revoke'])->name('revoke');
        });
    });
});
