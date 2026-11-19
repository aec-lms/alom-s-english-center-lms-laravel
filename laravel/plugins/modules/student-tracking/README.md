# Student Tracking & Analytics

An independently installable LMS plugin. It has no required sibling-plugin dependencies and deliberately uses no foreign keys to optional academic modules.

## Captured events

- Student login
- Course enrollment
- Protected lesson opened
- Protected audio/video lesson playback completed
- Exam/quiz submitted with result percentage
- Attendance marked

The authenticated `POST /student-tracking/events` endpoint can accept only the event types declared by `StudentActivityTracker::EVENT_TYPES`. It always assigns the event to the currently authenticated account; clients cannot submit another student's user ID.

## Admin and student pages

- Admin report: `/admin/student-tracking` (permission: `student-tracking.view`)
- Student's own history: `/student-tracking/mine`
- Event endpoint: `POST /student-tracking/events`

The admin report is branch-scoped using the Student Profile branch as the authoritative assignment, with the legacy user branch used only when no profile branch exists. Students can see only their own activity history.

## Integration from another plugin

When this plugin is enabled, another plugin may optionally call the service without declaring a hard dependency:

```php
if (app(\\App\\Core\\Plugins\\PluginManager::class)->isEnabled('student-tracking')) {
    app(\\Plugins\\Modules\\StudentTracking\\Services\\StudentActivityTracker::class)
        ->record($studentUserId, 'lesson_completed', 'lesson', $lessonId, $seconds, [
            'course_title' => $courseTitle,
            'lesson_title' => $lessonTitle,
            'source' => 'your-plugin',
        ]);
}
```

## Privacy and security

Only allow-listed event types and metadata fields are stored. The plugin does not capture keystrokes, screen contents, passwords, payment credentials, precise location, or browser fingerprinting. Event records contain account IDs, event type, optional subject identifiers, duration, allow-listed context and timestamp. The plugin has no hard dependency on sibling plugins. When Student Management, Course Management, Guardian Management, or Branch Management is enabled, it uses those tables as optional integrations with schema guards; it remains installable without them.

Uninstalling the plugin preserves activity history. Data removal must be an explicit administrator-controlled purge, not an implicit uninstall side effect.
