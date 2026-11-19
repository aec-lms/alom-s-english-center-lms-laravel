# CMS Scheduled Publishing — cPanel Setup

Scheduled CMS publishing is executed by Laravel's scheduler. The application registers the CMS publishing task every minute; cPanel must invoke Laravel's scheduler once per minute for scheduled posts to publish on time.

## cPanel setup (no SSH required)

1. Sign in to cPanel.
2. Open **Cron Jobs**.
3. Under **Add New Cron Job**, choose **Once Per Minute** (`* * * * *`).
4. In **Command**, enter the PHP CLI command for this installation:

   ```sh
   /usr/local/bin/php /home/CPANEL_USERNAME/APP_DIRECTORY/artisan schedule:run >> /dev/null 2>&1
   ```

5. Replace:
   - `CPANEL_USERNAME` with the cPanel account username.
   - `APP_DIRECTORY` with the actual Laravel application directory containing the `artisan` file.
   - `/usr/local/bin/php` with the PHP 8.3 CLI binary shown by the hosting provider if it differs.
6. Save the cron job.

## Important deployment checks

- The command must point to the Laravel application root, not the public web directory unless `artisan` is actually located there.
- Do not use `schedule:work` on shared cPanel hosting; use `schedule:run` once per minute.
- Keep the Laravel application timezone aligned with the timezone used when editors choose a scheduled publication time.
- The scheduler task is named and uses overlap protection to avoid concurrent publication runs.
- Only posts in the `approved` editorial state are eligible for automatic publication. Draft, in-review and changes-requested posts remain unpublished even if they have a past `publish_at` value.
- If the hosting provider uses a different PHP CLI path, ask hosting support for the PHP 8.3 command-line binary path rather than guessing.

## Verification

1. Create a test post and set a publication time one or two minutes in the future.
2. Submit it for editorial review and approve it before the scheduled time.
3. Confirm it remains unpublished before the scheduled time.
4. After the scheduled time, confirm the post is published and its editorial state is `published`.
5. Repeat with an unapproved draft and confirm it remains unpublished.

Never verify scheduled publishing with a production announcement. Use a private test post or staging environment first.
