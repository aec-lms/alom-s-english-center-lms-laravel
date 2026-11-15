<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Plugins\PluginManager;
use Illuminate\Console\Command;

class SyncPluginsCommand extends Command
{
    protected $signature = 'lms:plugins:sync';
    protected $description = 'Discover and synchronize plugin manifests into the Core plugin catalog.';

    public function handle(PluginManager $manager): int
    {
        $manager->syncCatalog();
        $this->info('Plugin catalog synchronized: ' . count($manager->discoverManifests()) . ' plugin(s) discovered.');

        return self::SUCCESS;
    }
}
