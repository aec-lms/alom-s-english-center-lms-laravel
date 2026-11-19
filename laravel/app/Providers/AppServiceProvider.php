<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Plugins\AdminMenuRegistry;
use App\Models\SystemSetting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Core bindings
    }

    public function boot(): void
    {
        // Keep public endpoints in independent IP buckets. Generic throttle:N,1
        // middleware shares counters across routes with the same limits and can
        // otherwise make unrelated contact/recovery actions block one another.
        RateLimiter::for('public-coupon-check', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('public-contact', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('password-reset-email', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));
        RateLimiter::for('password-otp-send', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('password-otp-verify', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('password-reset-submit', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        $this->loadInstitutionDefaults();

        View::composer('layouts.admin', function ($view): void {
            try {
                $view->with('pluginAdminMenu', Schema::hasTable('plugins') ? app(AdminMenuRegistry::class)->groups() : []);
            } catch (\Throwable $exception) {
                report_if(app()->environment('production'), $exception);
                $view->with('pluginAdminMenu', []);
            }
        });

        View::composer(['layouts.admin', 'layouts.app', 'public.partials.navbar', 'public.partials.footer', 'auth.login'], function ($view): void {
            try {
                $view->with('siteIdentity', app(\App\Core\Appearance\SiteIdentityManager::class)->values());
            } catch (\Throwable $exception) {
                report_if(app()->environment('production'), $exception);
                $view->with('siteIdentity', []);
            }
        });

        Gate::before(function ($user, string $ability): ?bool {
            if (method_exists($user, 'hasPermission') && $user->hasPermission($ability)) {
                return true;
            }
            return null;
        });
    }

    private function loadInstitutionDefaults(): void
    {
        try {
            if (!Schema::hasTable('system_settings')) {
                return;
            }
            $settings = SystemSetting::query()
                ->whereIn('key', ['institution.name', 'institution.website', 'localization.locale', 'localization.timezone', 'finance.default_currency'])
                ->pluck('value', 'key');
            $name = $settings->get('institution.name');
            $website = $settings->get('institution.website');
            $locale = $settings->get('localization.locale');
            $timezone = $settings->get('localization.timezone');
            $currency = $settings->get('finance.default_currency');
            $config = [];
            if (is_string($name) && $name !== '') $config['app.name'] = $name;
            if (is_string($website) && filter_var($website, FILTER_VALIDATE_URL)) $config['app.url'] = $website;
            if (is_string($locale) && preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $locale)) { $config['app.locale'] = $locale; $config['lms.default_locale'] = $locale; }
            if (is_string($timezone) && (in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) || $timezone === 'UTC')) {
                $config['app.timezone'] = $timezone;
                date_default_timezone_set($timezone);
            }
            if (is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency)) $config['lms.default_currency'] = $currency;
            if ($config !== []) config($config);
        } catch (\Throwable $exception) {
            // A fresh installer or isolated test may not have the settings table yet.
            report_if(app()->environment('production'), $exception);
        }
    }
}
