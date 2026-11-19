<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LanguageController extends Controller
{
    public function switch(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, ['en', 'bn'], true), 404);

        $request->session()->put('locale', $locale);
        $target = '/';
        $referer = $request->headers->get('referer');

        if (is_string($referer) && $referer !== '') {
            $parts = parse_url($referer);
            $host = isset($parts['host']) ? strtolower((string) $parts['host']) : null;
            $sameHost = $host === null || $host === strtolower($request->getHost());
            $path = (string) ($parts['path'] ?? '/');

            if ($sameHost && str_starts_with($path, '/') && !str_starts_with($path, '//')) {
                $target = $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
            }
        }

        return redirect()->to($target);
    }
}
