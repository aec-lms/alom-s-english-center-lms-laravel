<?php

declare(strict_types=1);

namespace Plugins\Modules\StudentTracking\Providers;

use App\Core\Plugins\BasePluginServiceProvider;
use App\Core\Plugins\PluginManager;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\StudentTracking\Services\StudentActivityTracker;
use Throwable;

class StudentTrackingServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'student-tracking';
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(Login::class, function (Login $event): void {
            $user = $event->user;
            if (app(PluginManager::class)->isEnabled('student-tracking')
                && method_exists($user, 'hasRole')
                && $user->hasRole('student')) {
                app(StudentActivityTracker::class)->record(
                    (int) $user->id,
                    'login',
                    'session',
                    null,
                    null,
                    ['source' => 'web-login'],
                    Auth::id(),
                );
            }
        });

        // Listen by event name rather than importing sibling model classes so
        // Student Tracking remains installable without academic plugins.
        $this->listenForModelEvent(
            'Plugins\\Modules\\CourseManagement\\Models\\Enrollment',
            'course_enrolled',
            'user_id',
            'course_id',
            'course',
        );
        $this->listenForModelEvent(
            'Plugins\\Modules\\ExamManagement\\Models\\ExamAttempt',
            'quiz_submitted',
            'user_id',
            'exam_id',
            'exam',
            function (object $model): array {
                $total = (float) ($model->total_marks ?? 0);
                $score = (float) ($model->score_obtained ?? 0);

                return ['result_percent' => $total > 0 ? round(($score / $total) * 100, 2) : 0];
            },
        );
        Event::listen('lms.live_class.joined', function (object $student, object $liveClass): void {
            if (app(PluginManager::class)->isEnabled('student-tracking')
                && method_exists($student, 'hasRole')
                && $student->hasRole('student')
                && Schema::hasTable('student_activity_events')) {
                app(StudentActivityTracker::class)->record(
                    (int) $student->id,
                    'live_class_joined',
                    'live_class',
                    $liveClass->id ?? null,
                    null,
                    ['source' => 'live-class-join'],
                    Auth::id(),
                );
            }
        });

        $this->listenForLiveClassAndCertificateEvents();
        $this->listenForAttendanceEvents();
    }

    private function listenForModelEvent(
        string $modelClass,
        string $eventType,
        string $userKey,
        string $subjectKey,
        string $subjectType,
        ?callable $metadataResolver = null,
    ): void {
        Event::listen('eloquent.created: ' . $modelClass, function (object $model) use (
            $eventType,
            $userKey,
            $subjectKey,
            $subjectType,
            $metadataResolver,
        ): void {
            if (!app(PluginManager::class)->isEnabled('student-tracking')
                || !Schema::hasTable('student_activity_events')) {
                return;
            }

            $userId = (int) ($model->{$userKey} ?? 0);
            if ($userId <= 0) {
                return;
            }

            try {
                $metadata = $metadataResolver ? $metadataResolver($model) : ['source' => 'lms-event'];
                app(StudentActivityTracker::class)->record(
                    $userId,
                    $eventType,
                    $subjectType,
                    $model->{$subjectKey} ?? $model->id ?? null,
                    null,
                    $metadata,
                    Auth::id(),
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    private function listenForLiveClassAndCertificateEvents(): void
    {
        $this->listenForModelEvent(
            'Plugins\\\\Modules\\\\CourseManagement\\\\Models\\\\LiveClassParticipant',
            'live_class_joined',
            'user_id',
            'live_class_id',
            'live_class',
            fn (object $model): array => ['source' => 'live-class-participant'],
        );

        Event::listen(
            'eloquent.created: Plugins\\\\Modules\\\\CertificateManagement\\\\Models\\\\CertificateRecord',
            function (object $certificate): void {
                if (!app(PluginManager::class)->isEnabled('student-tracking')
                    || !app(PluginManager::class)->isEnabled('student-management')
                    || !Schema::hasTable('student_activity_events')
                    || !Schema::hasTable('students')
                    || ($certificate->status ?? 'issued') !== 'issued') {
                    return;
                }

                $studentCode = (string) ($certificate->student_code ?? '');
                if ($studentCode === '') {
                    return;
                }

                $userId = (int) DB::table('students')->where('student_code', $studentCode)->value('user_id');
                if ($userId <= 0) {
                    return;
                }

                try {
                    app(StudentActivityTracker::class)->record(
                        $userId,
                        'certificate_earned',
                        'certificate',
                        $certificate->id ?? null,
                        null,
                        [
                            'course_title' => $certificate->course_title ?? '',
                            'source' => 'certificate-issued',
                        ],
                        Auth::id(),
                    );
                } catch (Throwable $exception) {
                    report($exception);
                }
            },
        );
    }

    private function listenForAttendanceEvents(): void
    {
        Event::listen(
            'eloquent.created: Plugins\\Modules\\AttendanceManagement\\Models\\AttendanceRecord',
            function (object $model): void {
                if (!app(PluginManager::class)->isEnabled('student-tracking')
                    || !Schema::hasTable('student_activity_events')
                    || !Schema::hasTable('students')) {
                    return;
                }

                $studentId = (int) ($model->student_id ?? 0);
                $userId = $studentId > 0
                    ? (int) DB::table('students')->where('id', $studentId)->value('user_id')
                    : 0;
                if ($userId <= 0) {
                    return;
                }

                try {
                    app(StudentActivityTracker::class)->record(
                        $userId,
                        'attendance_marked',
                        'attendance',
                        $model->id ?? null,
                        null,
                        ['attendance_status' => $model->status ?? 'recorded'],
                        Auth::id(),
                    );
                } catch (Throwable $exception) {
                    report($exception);
                }
            },
        );
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
