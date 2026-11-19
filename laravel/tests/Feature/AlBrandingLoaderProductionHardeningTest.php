<?php
declare(strict_types=1);

namespace Tests\Feature;

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
    }

    public function test_first_visit_is_recorded_only_after_a_successful_window_load(): void
    {
        $view = $this->loaderView();
        $this->assertStringContainsString("window.addEventListener('load', function ()", $view);
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
    }

    public function test_admin_payment_api_and_live_class_routes_are_excluded_from_navigation_loader(): void
    {
        $view = $this->loaderView();

        $this->assertStringContainsString('/(admin|api|payment|payments|checkout|webhook|webhooks|live-class|live-classes)', $view);
    }
}
