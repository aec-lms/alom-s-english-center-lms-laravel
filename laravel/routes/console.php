<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('lms:plugins:sync')->daily();
Schedule::command('lms:health-check --recover')->everyMinute()->withoutOverlapping(2);
