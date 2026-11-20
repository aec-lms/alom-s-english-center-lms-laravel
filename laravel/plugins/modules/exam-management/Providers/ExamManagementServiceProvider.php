<?php

declare(strict_types=1);

namespace Plugins\Modules\ExamManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ExamManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'exam-management'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }

    public function boot(): void
    {
        parent::boot();

        RateLimiter::for('exam-take', fn (Request $request) => Limit::perMinute(20)->by($this->examRateLimitKey($request, 'take')));
        RateLimiter::for('exam-history', fn (Request $request) => Limit::perMinute(30)->by($this->examRateLimitKey($request, 'history')));
        RateLimiter::for('exam-submit', fn (Request $request) => Limit::perMinute(5)->by($this->examRateLimitKey($request, 'submit')));
        RateLimiter::for('exam-autosave', fn (Request $request) => Limit::perMinute(30)->by($this->examRateLimitKey($request, 'autosave')));
    }

    private function examRateLimitKey(Request $request, string $action): string
    {
        $userId = $request->user()?->getAuthIdentifier();
        $exam = $request->route('exam');
        $examId = is_object($exam) && method_exists($exam, 'getKey')
            ? (string) $exam->getKey()
            : (is_scalar($exam) ? (string) $exam : 'unknown');

        $actor = $userId !== null ? 'user:' . $userId : 'ip:' . $request->ip();

        return $action . ':' . $actor . ':exam:' . $examId;
    }
}
