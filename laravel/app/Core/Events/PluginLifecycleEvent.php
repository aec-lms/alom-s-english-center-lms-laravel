<?php

declare(strict_types=1);

namespace App\Core\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PluginLifecycleEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $action,
        public readonly string $slug,
        public readonly string $version,
        public readonly ?int $actorId = null,
        public readonly array $context = [],
    ) {}
}
