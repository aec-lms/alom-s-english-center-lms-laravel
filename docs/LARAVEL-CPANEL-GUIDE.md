# AEC LMS — Laravel 12 cPanel Shared Hosting & Operations Guide

## 1. Build the Production Release Package
Run the packaging script on your build server or CI runner (requires `bash`, `rsync`, `zip`, and `composer`):

```bash
chmod +x laravel/scripts/build-cpanel-package.sh
./laravel/scripts/build-cpanel-package.sh
```

The resulting archive `laravel/dist/aec-lms-cpanel-release.zip` excludes `.env`, secrets, `.git`, tests, local SQLite databases, and `storage/app/installed.lock`.

## 2. cPanel Directory Mapping
1. Create a fresh, **completely empty** MySQL/MariaDB database and database user in cPanel.
2. Upload and extract `aec-lms-cpanel-release.zip` outside `public_html` (e.g. `/home/<user>/aec-lms`).
3. Point your domain's document root to `/home/<user>/aec-lms/public` (or symlink `public_html` to `aec-lms/public`).
4. Copy `.env.example` to `.env`, set `APP_URL`, `DB_*` credentials, and leave `APP_KEY` blank or generate one (`php artisan key:generate`).

## 3. Fresh Installation (Web Wizard or SSH)
- Visit `https://yourdomain.com/install` to run the Fresh Database Installer Wizard.
- Or run via SSH:
  ```bash
  php artisan aec:install-fresh --name="Principal Admin" --email="admin@yourdomain.com" --password="StrongPassword!2026"
  php artisan aec:sync-plugins
  ```
- Once installed, `storage/app/installed.lock` locks `/install` permanently (returns HTTP 404).

## 4. Cron Scheduler & Queue Worker on cPanel
Add a single cPanel Cron Job running every minute:
```cron
* * * * * cd /home/<user>/aec-lms && php artisan schedule:run >> /dev/null 2>&1
```

## 5. Security & Payment Gateway Separation
- `gateway-sslcommerz` handles ONLY SSLCOMMERZ Hosted Checkout redirects and IPN hash verification.
- `gateway-card` handles ONLY Visa/Mastercard tokenized checkout tokens (`payment_token`, `card_brand`, `last4`) and HMAC webhooks; raw PAN and CVV values are rejected with HTTP 422.
- All sensitive plugin keys (`store_password`, `api_secret`, `webhook_secret`, `role-ai-assistant.api_key`) are encrypted at rest via Laravel `Crypt`.
