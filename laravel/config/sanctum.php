<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;

$tokenExpiration = env('SANCTUM_TOKEN_EXPIRATION', 10080);

return [
    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort()
    ))),
    'guard' => ['web'],
    'expiration' => $tokenExpiration === null || $tokenExpiration === '' ? null : min(43200, max(1, (int) $tokenExpiration)),
    'token_prefix' => (string) env('SANCTUM_TOKEN_PREFIX', 'aec_'),
    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
