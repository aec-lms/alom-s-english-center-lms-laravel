<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetApplicationLocale
{
    private const SUPPORTED_LOCALES = ['en', 'bn'];

    public function handle(Request $request, Closure $next): Response
    {
        $sessionLocale = $request->hasSession() ? $request->session()->get('locale') : null;
        $configuredLocale = str_replace('_', '-', (string) config('lms.default_locale', config('app.locale', 'en')));
        $defaultLocale = strtolower(explode('-', $configuredLocale)[0] ?? 'en');
        $locale = is_string($sessionLocale) ? $sessionLocale : $defaultLocale;

        if (!in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $locale = in_array($defaultLocale, self::SUPPORTED_LOCALES, true) ? $defaultLocale : 'en';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
