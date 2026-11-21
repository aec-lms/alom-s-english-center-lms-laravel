<?php

declare(strict_types=1);

use App\Core\Plugins\PluginManager;
use Illuminate\Contracts\Console\Kernel;
use Plugins\Modules\LearningRewards\Services\LearningRewardsService;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'mysql',
    'database.connections.mysql.host' => (string) getenv('DB_HOST'),
    'database.connections.mysql.port' => (string) getenv('DB_PORT'),
    'database.connections.mysql.database' => (string) getenv('DB_DATABASE'),
    'database.connections.mysql.username' => (string) getenv('DB_USERNAME'),
    'database.connections.mysql.password' => (string) getenv('DB_PASSWORD'),
    'database.connections.mysql.strict' => true,
]);
\Illuminate\Support\Facades\DB::purge('mysql');

$plugins = $app->make(PluginManager::class);
if (!$plugins->isEnabled('learning-rewards')) {
    fwrite(STDERR, "Learning Rewards plugin is not enabled in the disposable test database.\n");
    exit(2);
}

$readyFile = (string) getenv('AEC_REWARD_READY_FILE');
$startGate = (string) getenv('AEC_REWARD_START_GATE');
if ($readyFile === '' || $startGate === '') {
    fwrite(STDERR, "Missing reward worker synchronization paths.\n");
    exit(3);
}
file_put_contents($readyFile, (string) getmypid(), LOCK_EX);
$deadline = microtime(true) + 15;
while (!is_file($startGate) && microtime(true) < $deadline) {
    usleep(10000);
}
if (!is_file($startGate)) {
    fwrite(STDERR, "Timed out waiting for reward worker start gate.\n");
    exit(4);
}

try {
    $result = $app->make(LearningRewardsService::class)->awardVerifiedActivity(
        (int) getenv('AEC_REWARD_USER_ID'),
        (string) getenv('AEC_REWARD_ACTIVITY'),
        'course_lesson',
        (int) getenv('AEC_REWARD_SOURCE_ID'),
    );
    fwrite(STDOUT, json_encode(['success' => true, 'result' => $result], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode([
        'success' => false,
        'class' => get_class($exception),
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
