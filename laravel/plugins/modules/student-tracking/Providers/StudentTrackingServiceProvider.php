<?php

declare(strict_types=1);

namespace Plugins\\Modules\\StudentTracking\\Providers;

use App\\Core\\Plugins\\BasePluginServiceProvider;
use Illuminate\\Auth\\Events\\Login;
use Illuminate\\Support\\Facades\\Event;
use Plugins\\Modules\\StudentTracking\\Services\\StudentActivityTracker;

class StudentTrackingServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'student-tracking'; }

    public function boot(): void
    {
        parent::boot();
        Event::listen(Login::class, function (Login $event): void {
            $user = $event->user;
            if (method_exists($user, 'hasRole') && $user->hasRole('student')) {
                app(StudentActivityTracker::class)->record((int) $user->id, 'login', 'session', null, null, ['source' => 'web-login']);
            }
        });
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
