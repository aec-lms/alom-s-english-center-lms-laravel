<?php

declare(strict_types=1);

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use Plugins\Modules\PaymentManagement\Models\PaymentRefund;
use Plugins\Modules\PaymentManagement\Services\RefundOrchestrationService;
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

    DB::transaction(function (): void {
        $duePosts = DB::table('cms_posts')
            ->whereNull('deleted_at')
            ->where('is_published', false)
            ->where('editorial_status', 'approved')
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now())
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        foreach ($duePosts as $duePost) {
            $updated = DB::table('cms_posts')
                ->where('id', $duePost->id)
                ->whereNull('deleted_at')
                ->where('is_published', false)
                ->where('editorial_status', 'approved')
                ->where('publish_at', '<=', now())
                ->update([
                    'is_published' => true,
                    'editorial_status' => 'published',
                    'publish_at' => null,
                    'updated_at' => now(),
                ]);

            if ($updated === 1) {
                app(AuditLogger::class)->log(
                    'cms.post_scheduled_published',
                    'cms_post',
                    (string) $duePost->id,
                    ['source' => 'scheduler'],
                    null
                );
            }
        }
    }, 3);
})->name('cms-publish-scheduled-posts')->everyMinute()->withoutOverlapping();


Schedule::call(function (): void {
    if (!app(PluginManager::class)->isEnabled('payment-management')
        || !Schema::hasTable('payment_refunds')
        || !Schema::hasColumn('payment_refunds', 'status')
        || !Schema::hasColumn('payment_refunds', 'provider_refund_reference')
        || !Schema::hasColumn('payment_refunds', 'reconciled_at')) {
        return;
    }

    // Only provider-pending rows with a provider reference are safe to poll.
    // Ambiguous rows without a reference remain in manual review and are never
    // resubmitted automatically.
    PaymentRefund::query()
        ->whereIn('status', ['submitted', 'pending'])
        ->whereNotNull('provider_refund_reference')
        ->orderByRaw('reconciled_at IS NULL DESC')
        ->orderBy('reconciled_at')
        ->orderBy('id')
        ->limit(25)
        ->get()
        ->each(function (PaymentRefund $refund): void {
            try {
                app(RefundOrchestrationService::class)->reconcile($refund, null);
            } catch (\Throwable $exception) {
                // Do not log provider payloads, credentials or payer data.
                logger()->warning('Provider refund reconciliation failed.', [
                    'refund_id' => $refund->id,
                    'exception_class' => get_class($exception),
                ]);
            }
        });
})->name('payment-reconcile-provider-refunds')->everyTenMinutes()->withoutOverlapping();
