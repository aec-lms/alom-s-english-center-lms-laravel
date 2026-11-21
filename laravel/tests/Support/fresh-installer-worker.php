<?php

declare(strict_types=1);

use App\Core\Installer\FreshInstallerService;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

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
