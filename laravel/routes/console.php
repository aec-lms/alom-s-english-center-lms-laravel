<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('lms:plugins:sync')->daily();
Schedule::command('lms:health-check --recover')->everyMinute()->withoutOverlapping(2);
Schedule::command('lms:backup-create --without-files')->dailyAt('02:00')->withoutOverlapping(120);
Schedule::command('lms:backup-create')->weeklyOn(0, '02:30')->withoutOverlapping(180);
