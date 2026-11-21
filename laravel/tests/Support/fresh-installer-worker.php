<?php

declare(strict_types=1);

use App\Core\Installer\FreshInstallerService;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Explicitly pin the disposable process-test resources even if CI supplies a
// cached config file; no production/default database or storage path is used.
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => (string) getenv('DB_DATABASE'),
    'lms.install_lock_path' => (string) getenv('AEC_INSTALL_LOCK_PATH'),
    'lms.env_path' => (string) getenv('AEC_ENV_FILE_PATH'),
    'lms.private_storage_path' => (string) getenv('AEC_PRIVATE_STORAGE_PATH'),
    'lms.backup_path' => (string) getenv('AEC_BACKUP_PATH'),
]);
\Illuminate\Support\Facades\DB::purge('sqlite');

$readyFile = (string) getenv('AEC_INSTALLER_READY_FILE');
$startGate = (string) getenv('AEC_INSTALLER_START_GATE');
if ($readyFile === '' || $startGate === '') {
    fwrite(STDERR, "Missing worker synchronization paths.\n");
    exit(2);
}

file_put_contents($readyFile, (string) getmypid(), LOCK_EX);
$deadline = microtime(true) + 15;
while (!is_file($startGate) && microtime(true) < $deadline) {
    usleep(10000);
}
if (!is_file($startGate)) {
    fwrite(STDERR, "Timed out waiting for parent start gate.\n");
    exit(3);
}

try {
    $result = $app->make(FreshInstallerService::class)->installFresh([
        'app_name' => 'AEC Concurrent Installer Test',
        'app_url' => 'https://aec-concurrent.test',
        'admin_name' => 'Concurrent Admin',
        'admin_email' => 'concurrent-admin@aec.test',
        'admin_password' => 'ConcurrentStrongPassword!2026',
        'country_code' => 'BD',
        'locale' => 'en',
        'timezone' => 'Asia/Dhaka',
        'currency' => 'BDT',
    ]);
    fwrite(STDOUT, json_encode(['success' => true, 'result' => $result], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode([
        'success' => false,
        'class' => get_class($exception),
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
