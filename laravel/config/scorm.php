<?php

declare(strict_types=1);

return [
    // Must be a separate origin (preferably a dedicated subdomain) with no shared
    // Laravel authentication cookie. SCORM packages are untrusted active HTML.
    'player_origin' => rtrim((string) env('SCORM_PLAYER_ORIGIN', ''), '/'),
    'content_origin' => rtrim((string) env('SCORM_CONTENT_ORIGIN', ''), '/'),
    // "lax" is only used by the same-site, multi-host HTTP browser fixture. Real
    // cross-site production deployments must keep the secure "none" default.
    'cookie_same_site' => in_array((string) env('SCORM_COOKIE_SAME_SITE', 'none'), ['lax', 'strict', 'none'], true)
        ? (string) env('SCORM_COOKIE_SAME_SITE', 'none')
        : 'none',
    'launch_ttl_minutes' => max(15, min(480, (int) env('SCORM_LAUNCH_TTL_MINUTES', 240))),
];
