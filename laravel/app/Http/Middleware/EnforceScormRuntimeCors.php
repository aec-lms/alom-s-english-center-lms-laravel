<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce the trusted player origin after Laravel's generic CORS middleware.
 */
class EnforceScormRuntimeCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (!$request->is('api/scorm-runtime/commit')) {
            return $response;
        }

        // The generic API CORS middleware permits wildcard origins by default.
        // Remove those headers and re-issue CORS permission only for our trusted
        // player host; the controller separately validates Origin and the token.
        foreach ([
            'Access-Control-Allow-Origin',
            'Access-Control-Allow-Credentials',
            'Access-Control-Allow-Methods',
            'Access-Control-Allow-Headers',
            'Access-Control-Max-Age',
        ] as $header) {
            $response->headers->remove($header);
        }

        $allowedOrigin = rtrim((string) config('scorm.player_origin', ''), '/');
        $requestOrigin = rtrim((string) $request->headers->get('Origin', ''), '/');
        if ($allowedOrigin !== '' && $requestOrigin !== '' && hash_equals($allowedOrigin, $requestOrigin)) {
            $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
            $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
            $response->headers->set('Access-Control-Max-Age', '600');
            $vary = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary', ''))));
            $vary[] = 'Origin';
            $response->headers->set('Vary', implode(', ', array_values(array_unique($vary))));
        }

        return $response;
    }
}
