#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
STAGE_DIR="${DIST_DIR}/aec-lms-cpanel"
ARCHIVE_PATH="${DIST_DIR}/aec-lms-cpanel-release.zip"

echo "==> Building AEC LMS cPanel Production Package from ${ROOT_DIR}"

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
    --exclude='storage/framework/cache/data/*' \
    --exclude='storage/framework/sessions/*' \
    --exclude='storage/framework/views/*' \
    --exclude='storage/app/installed.lock' \
    --exclude='storage/app/backups/*' \
    --exclude='*.sqlite' \
    --exclude='*.sqlite3' \
    "${ROOT_DIR}/" "${STAGE_DIR}/"
else
  tar -C "${ROOT_DIR}" \
    --exclude='.git' \
    --exclude='.env' \
    --exclude='node_modules' \
    --exclude='tests' \
    --exclude='dist' \
    --exclude='storage/logs/*.log' \
    --exclude='storage/framework/cache/data/*' \
    --exclude='storage/framework/sessions/*' \
    --exclude='storage/framework/views/*' \
    --exclude='storage/app/installed.lock' \
    --exclude='storage/app/backups/*' \
    --exclude='*.sqlite' \
    --exclude='*.sqlite3' \
    -cf - . | tar -C "${STAGE_DIR}" -xf -
fi

mkdir -p \
  "${STAGE_DIR}/storage/app/private" \
  "${STAGE_DIR}/storage/app/backups" \
  "${STAGE_DIR}/storage/framework/cache/data" \
  "${STAGE_DIR}/storage/framework/sessions" \
  "${STAGE_DIR}/storage/framework/views" \
  "${STAGE_DIR}/storage/logs" \
  "${STAGE_DIR}/bootstrap/cache"

if command -v composer >/dev/null 2>&1; then
  (cd "${STAGE_DIR}" && composer install --no-dev --optimize-autoloader --no-interaction)
else
  echo "WARN: composer not found; vendor/ was not bundled into the stage directory."
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

echo "==> Created cPanel release package: ${ARCHIVE_PATH}"
