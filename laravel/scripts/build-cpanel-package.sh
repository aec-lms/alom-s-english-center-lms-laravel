#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
STAGE_DIR="${DIST_DIR}/aec-lms-cpanel"
ARCHIVE_PATH="${DIST_DIR}/aec-lms-cpanel-release.zip"

echo "==> Building AEC LMS cPanel Production Package from ${ROOT_DIR}"

if [[ ! -f "${ROOT_DIR}/composer.lock" ]]; then
  echo "ERROR: composer.lock is required for a reproducible production package."
  exit 1
fi
if ! command -v composer >/dev/null 2>&1; then
  echo "ERROR: Composer 2 is required to build the production package."
  exit 1
fi

rm -rf "${STAGE_DIR}" "${ARCHIVE_PATH}"
mkdir -p "${STAGE_DIR}"

if command -v rsync >/dev/null 2>&1; then
  rsync -a \
    --exclude='.git' \
    --exclude='.env' \
    --exclude='.env.*' \
    --include='.env.example' \
    --exclude='node_modules' \
    --exclude='tests' \
    --exclude='dist' \
    --exclude='storage/logs/*.log' \
    --exclude='storage/framework/cache/**' \
    --exclude='storage/framework/testing/**' \
    --exclude='.phpunit.result.cache' \
    --exclude='phpunit.xml' \
    --exclude='storage/framework/sessions/*' \
    --exclude='storage/framework/views/*' \
    --exclude='storage/app/installed.lock*' \
    --exclude='storage/app/backups/*' \
    --exclude='storage/app/plugin-recycle-bin/**' \
    --exclude='storage/app/plugin-setting-backups/**' \
    --exclude='storage/app/plugin-permanent-delete-backups/**' \
    --exclude='storage/app/private/**' \
    --exclude='storage/app/restore-staging/**' \
    --exclude='storage/app/plugin-staging/**' \
    --exclude='storage/app/plugin-rollbacks/**' \
    --exclude='storage/app/update-staging/**' \
    --exclude='storage/app/update-snapshots/**' \
    --exclude='bootstrap/cache/*.php' \
    --exclude='public/hot' \
    --exclude='*.sqlite' \
    --exclude='*.sqlite3' \
    "${ROOT_DIR}/" "${STAGE_DIR}/"
else
  tar -C "${ROOT_DIR}" \
    --exclude='.git' \
    --exclude='.env' \
    --exclude='.env.*' \
    --exclude='node_modules' \
    --exclude='tests' \
    --exclude='dist' \
    --exclude='storage/logs/*.log' \
    --exclude='storage/framework/cache/**' \
    --exclude='storage/framework/testing/**' \
    --exclude='.phpunit.result.cache' \
    --exclude='phpunit.xml' \
    --exclude='storage/framework/sessions/*' \
    --exclude='storage/framework/views/*' \
    --exclude='storage/app/installed.lock*' \
    --exclude='storage/app/backups/*' \
    --exclude='storage/app/plugin-recycle-bin/**' \
    --exclude='storage/app/plugin-setting-backups/**' \
    --exclude='storage/app/plugin-permanent-delete-backups/**' \
    --exclude='storage/app/private/**' \
    --exclude='storage/app/restore-staging/**' \
    --exclude='storage/app/plugin-staging/**' \
    --exclude='storage/app/plugin-rollbacks/**' \
    --exclude='storage/app/update-staging/**' \
    --exclude='storage/app/update-snapshots/**' \
    --exclude='bootstrap/cache/*.php' \
    --exclude='public/hot' \
    --exclude='*.sqlite' \
    --exclude='*.sqlite3' \
    -cf - . | tar -C "${STAGE_DIR}" -xf -
fi

if [[ -f "${ROOT_DIR}/.env.example" ]]; then
  cp "${ROOT_DIR}/.env.example" "${STAGE_DIR}/.env.example"
fi

mkdir -p \
  "${STAGE_DIR}/storage/app/private" \
  "${STAGE_DIR}/storage/app/backups" \
  "${STAGE_DIR}/storage/framework/cache/data" \
  "${STAGE_DIR}/storage/framework/sessions" \
  "${STAGE_DIR}/storage/framework/views" \
  "${STAGE_DIR}/storage/logs" \
  "${STAGE_DIR}/bootstrap/cache"

if [[ -f "${STAGE_DIR}/.env.example" ]]; then
  cp "${STAGE_DIR}/.env.example" "${STAGE_DIR}/.env"
fi

(cd "${STAGE_DIR}" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress)
rm -f "${STAGE_DIR}/.env"

if [[ ! -f "${STAGE_DIR}/vendor/autoload.php" ]]; then
  echo "ERROR: Composer did not produce vendor/autoload.php."
  exit 1
fi

if command -v zip >/dev/null 2>&1; then
  (cd "${STAGE_DIR}" && zip -rq "${ARCHIVE_PATH}" .)
else
  python3 - "${STAGE_DIR}" "${ARCHIVE_PATH}" <<'PYEOF'
import os, sys, zipfile
stage_dir, archive_path = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(archive_path, 'w', zipfile.ZIP_DEFLATED) as zf:
    for root, dirs, files in os.walk(stage_dir):
        rel_root = os.path.relpath(root, stage_dir)
        for d in dirs:
            full_d = os.path.join(root, d)
            arc_d = os.path.relpath(full_d, stage_dir) + '/'
            zf.writestr(arc_d, '')
        for f in files:
            full_f = os.path.join(root, f)
            arc_f = os.path.relpath(full_f, stage_dir)
            zf.write(full_f, arc_f)
PYEOF
fi

if command -v sha256sum >/dev/null 2>&1; then
  sha256sum "${ARCHIVE_PATH}" > "${ARCHIVE_PATH}.sha256"
else
  php -r 'echo hash_file("sha256", $argv[1]), "  ", basename($argv[1]), PHP_EOL;' "${ARCHIVE_PATH}" > "${ARCHIVE_PATH}.sha256"
fi

echo "==> Created cPanel release package: ${ARCHIVE_PATH}"
echo "==> SHA-256: ${ARCHIVE_PATH}.sha256"
