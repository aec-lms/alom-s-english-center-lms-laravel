<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Schedule::command('lms:plugins:sync')->daily();
Schedule::command('lms:health-check --recover')->everyMinute()->withoutOverlapping(2);
Schedule::command('lms:backup-create --without-files')->dailyAt('02:00')->withoutOverlapping(120);
Schedule::command('lms:backup-create')->weeklyOn(0, '02:30')->withoutOverlapping(180);
Schedule::command('lms:backup:offsite --no-download-verify')->dailyAt('03:00')->withoutOverlapping(180);
Schedule::command('lms:backup:offsite')->weeklyOn(0, '04:00')->withoutOverlapping(240);


Schedule::call(function (): void {
    if (!Schema::hasTable('cms_posts') || !Schema::hasColumn('cms_posts', 'publish_at') || !Schema::hasColumn('cms_posts', 'editorial_status')) {
        return;
    }

    DB::table('cms_posts')
        ->whereNull('deleted_at')
        ->where('is_published', false)
        ->where('editorial_status', 'approved')
        ->whereNotNull('publish_at')
        ->where('publish_at', '<=', now())
        ->update(['is_published' => true, 'editorial_status' => 'published', 'publish_at' => null, 'updated_at' => now()]);
})->name('cms-publish-scheduled-posts')->everyMinute()->withoutOverlapping();
