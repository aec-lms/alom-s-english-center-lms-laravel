<?php
declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\AlBrandingLoader\Models\LoaderSetting;
use Tests\TestCase;

final class AlBrandingLoaderProductionHardeningTest extends TestCase
{
    private function loaderView(): string
    {
        return file_get_contents(base_path('plugins/modules/al-branding-loader/Resources/views/components/loading-screen.blade.php'));
    }

    public function test_loader_has_a_hard_timeout_even_when_minimum_duration_is_longer(): void
    {
        $view = $this->loaderView();

        $this->assertStringContainsString('Math.min(', $view);
        $this->assertStringContainsString('Math.max(0, maxDuration - elapsed)', $view);
        $this->assertStringContainsString('window.setTimeout(forceHide, maxDuration)', $view);
        $this->assertStringContainsString('event.target instanceof Element', $view);
        $this->assertStringContainsString("document.readyState === 'complete'", $view);
        $this->assertStringContainsString('const onPageLoad = function ()', $view);
        $this->assertStringContainsString('transition:none!important', $view);
    }

    public function test_first_visit_is_recorded_only_after_a_successful_window_load(): void
    {
        $view = $this->loaderView();
        $this->assertStringContainsString("window.addEventListener('load', onPageLoad", $view);
        $this->assertStringContainsString('hideAfterMinimumDuration(true)', $view);
        $this->assertStringContainsString('if (!markVisit) return;', $view);
        $this->assertStringContainsString("sessionStorage.setItem(storageKey, '1')", $view);
        $this->assertStringContainsString('localStorage.setItem(storageKey, new Date().toDateString())', $view);
    }

    public function test_navigation_restarts_the_loader_timeout_without_stale_hide_timers(): void
    {
        $view = $this->loaderView();

        $this->assertStringContainsString("document.addEventListener('click'", $view);
        $this->assertStringContainsString('if (hideTimer !== null) window.clearTimeout(hideTimer)', $view);
        $this->assertStringContainsString('maxTimer = window.setTimeout(forceHide, maxDuration)', $view);
        $this->assertStringContainsString('destination.origin !== window.location.origin', $view);
        $this->assertStringContainsString('matchesConfiguredExclusion(destination.pathname)', $view);
        $this->assertStringContainsString('data-excluded-paths=', $view);
        $this->assertStringContainsString('Array.isArray(parsedExclusions)', $view);
    }

    public function test_admin_payment_api_and_live_class_routes_are_excluded_from_navigation_loader(): void
    {
        $view = $this->loaderView();

        $this->assertStringContainsString('/(admin|api|payment|payments|checkout|webhook|webhooks|live-class|live-classes)', $view);
    }
    public function test_model_respects_excluded_paths_and_keeps_public_pages_enabled(): void
    {
        $setting = new LoaderSetting([
            'enabled' => true,
            'excluded_paths' => ['admin/*', 'api/*', 'payment/*'],
        ]);

        $this->assertFalse($setting->shouldRenderForPath('admin/users'));
        $this->assertFalse($setting->shouldRenderForPath('api/courses'));
        $this->assertFalse($setting->shouldRenderForPath('payment/checkout'));
        $this->assertTrue($setting->shouldRenderForPath('courses'));
    }

    public function test_logo_url_rejects_paths_outside_the_plugin_upload_directory(): void
    {
        $setting = new LoaderSetting(['logo_path' => 'uploads/other-plugin/logo.png']);

        $this->assertNull($setting->logo_url);

        $setting->logo_path = 'uploads/alom-english-center/loader/valid-logo.png';

        $this->assertNotNull($setting->logo_url);
    }

    public function test_model_never_renders_when_disabled(): void
    {
        $setting = new LoaderSetting(['enabled' => false]);
        $this->assertFalse($setting->shouldRenderForPath('courses'));
    }

}
