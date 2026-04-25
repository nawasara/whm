<?php

namespace Nawasara\Whm\Console\Commands;

use Illuminate\Console\Command;
use Nawasara\Whm\Jobs\Account\SyncWhmAccountsJob;
use Nawasara\Whm\Services\AccountRegistrySync;
use Nawasara\Whm\Services\WhmClient;

class SyncAccountsCommand extends Command
{
    protected $signature = 'whm:sync-accounts
                            {--instance= : Specific WHM instance name (default: all hosting-role instances)}
                            {--registry : Also run legacy registry sync (link accounts → registry assets)}
                            {--sync : Run synchronously (skip queue) — for first run / debug}';

    protected $description = 'Sync WHM/cPanel accounts ke DB snapshot. Default: dispatches job ke queue.';

    public function handle(WhmClient $whm, AccountRegistrySync $registrySync): int
    {
        $instance = $this->option('instance');
        $alsoRegistry = (bool) $this->option('registry');
        $runSync = (bool) $this->option('sync');

        $instances = $instance
            ? [$instance]
            : $whm->instancesByRole('hosting');

        if (empty($instances)) {
            $this->warn('No WHM instances with role=hosting found.');
            return self::SUCCESS;
        }

        foreach ($instances as $name) {
            $this->info("Dispatching account sync for instance: {$name}");

            $job = new SyncWhmAccountsJob(
                instance: $name,
                triggerSource: 'scheduled',
            );

            if ($runSync) {
                try {
                    $job->handle();
                    $this->line('  ✓ Done synchronously');
                } catch (\Throwable $e) {
                    $this->error('  ✗ Failed: '.$e->getMessage());
                }
            } else {
                dispatch($job);
                $this->line('  → Queued');
            }
        }

        if ($alsoRegistry) {
            $this->info("\nRunning legacy registry sync...");
            $stats = $registrySync->sync();
            $this->table(
                ['total', 'created', 'linked', 'updated', 'unchanged', 'deactivated'],
                [[
                    $stats['total'],
                    $stats['created'],
                    $stats['linked'],
                    $stats['updated'],
                    $stats['unchanged'],
                    $stats['deactivated'],
                ]]
            );
        }

        return self::SUCCESS;
    }
}
