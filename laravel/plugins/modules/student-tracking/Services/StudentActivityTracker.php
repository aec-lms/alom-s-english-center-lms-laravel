<?php

declare(strict_types=1);

namespace Plugins\\Modules\\StudentTracking\\Services;

use Illuminate\\Support\\Facades\\Schema;
use InvalidArgumentException;
use Plugins\\Modules\\StudentTracking\\Models\\StudentActivityEvent;

class StudentActivityTracker
{
    public const EVENT_TYPES = [
        'login', 'lesson_started', 'lesson_completed', 'quiz_submitted',
        'live_class_joined', 'attendance_marked', 'course_enrolled', 'certificate_earned',
    ];

    public function record(
        int $userId,
        string $eventType,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        ?int $durationSeconds = null,
        array $metadata = [],
        ?int $actorId = null,
    ): StudentActivityEvent {
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported student activity event type.');
        }
        if (!Schema::hasTable('student_activity_events')) {
            throw new InvalidArgumentException('Student tracking storage is not installed.');
        }

        // Explicitly exclude keystrokes, screen contents, precise location and credentials.
        $safeMetadata = [];
        foreach (['course_title', 'lesson_title', 'batch_name', 'result_percent', 'source'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_scalar($value)) {
                $safeMetadata[$key] = mb_substr((string) $value, 0, 160);
            }
        }

        return StudentActivityEvent::query()->create([
            'user_id' => $userId,
            'actor_id' => $actorId ?? $userId,
            'event_type' => $eventType,
            'subject_type' => $subjectType ? mb_substr($subjectType, 0, 60) : null,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'duration_seconds' => $durationSeconds === null ? null : max(0, min($durationSeconds, 86400)),
            'metadata' => $safeMetadata ?: null,
            'occurred_at' => now(),
        ]);
    }
}
