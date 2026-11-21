<?php

declare(strict_types=1);

return [
    // Must be a separate origin (preferably a dedicated subdomain) with no shared
    // Laravel authentication cookie. SCORM packages are untrusted active HTML.
    'content_origin' => rtrim((string) env('SCORM_CONTENT_ORIGIN', ''), '/'),
    'launch_ttl_minutes' => max(15, min(480, (int) env('SCORM_LAUNCH_TTL_MINUTES', 240))),
];
