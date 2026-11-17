# LMS Lesson Content Protection

The course-management module includes an initial server-side lesson media protection layer.

## What it protects

- Lesson media is uploaded to AEC_PRIVATE_STORAGE_PATH (default: storage/app/private), outside the public web root.
- Supported upload formats: MP4, WebM, MP3, M4A and PDF, subject to PHP upload limits and server MIME detection.
- Uploads default to protection enabled and downloads disabled.
- A learner must be authenticated and have an active enrollment in the lesson's course.
- The LMS issues an account-bound, temporary signed media URL that expires after five minutes.
- The stream endpoint checks the account and enrollment again, validates the canonical file path, rejects traversal/symlink escapes, and sends private/no-store response headers.
- Admin endpoints allow course managers to upload lesson media and change the protection/download policy.

## Admin screen

Open the Course Management admin area and choose **Content Protection**. Administrators with `courses.manage` can upload lesson media and save per-lesson protection/download policy. Branch managers are restricted to their assigned active branch.

## Routes

- GET /admin/courses/content-protection — admin content protection dashboard; requires `courses.manage`.
- POST /admin/courses/lessons/{lesson}/media — multipart upload field media; requires courses.manage.
- PATCH /admin/courses/lessons/{lesson}/protection — JSON fields protection_enabled and is_downloadable; requires courses.manage.
- GET /my-learning/lessons/{lesson}/media-access — returns a short-lived signed URL and learner watermark text; requires login and active enrollment.
- GET /my-learning/lessons/{lesson}/media/{user} — signed, account-bound media response.\n- GET /my-learning/lessons/{lesson}/download/{user} — signed download endpoint; works only when the lesson download policy is enabled.\n- GET /my-learning/courses/{course}/lessons — enrolled learner player with moving visible watermark.

## Player integration

The learner player should request the media-access endpoint when playback starts, use the returned URL as its media source, and render the returned watermark text as a moving/periodically repositioned overlay over the player. Do not store signed URLs in page HTML, browser local storage, or long-lived caches. Refresh access when the five-minute URL expires.

The download_allowed value is policy metadata for the player UI. The player must hide download controls when false. The stream endpoint always serves media inline; browser developer tools, screen capture, external cameras, and some browser PDF controls cannot be fully disabled by a web application.

## Important limitations

This is application-level access control, not studio-grade DRM. It does not prevent screen recording or copying by a learner who can view the media. HLS/DASH segment encryption, Widevine/FairPlay/PlayReady DRM, per-user forensic watermarking, and a production player UI are separate integrations and require compatible media packaging and, for commercial DRM, licensed provider services.

## Deployment

1. Install/update the course-management plugin so its migration adds the four lesson media policy columns.
2. Configure AEC_PRIVATE_STORAGE_PATH to a writable directory outside public_html / the public document root.
3. Ensure the PHP upload limit and web-server request-body limit are sized for the intended upload maximum.
4. Back up the database and private media directory before deploying or changing media.
5. Run the content-protection feature tests before enabling protected lessons in production.
