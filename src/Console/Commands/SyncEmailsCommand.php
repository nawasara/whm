<?php

namespace Nawasara\Whm\Console\Commands;

use Illuminate\Console\Command;
use Nawasara\Whm\Jobs\Email\SyncWhmEmailsJob;
use Nawasara\Whm\Services\WhmClient;

class SyncEmailsCommand extends Command
{
    protected $signature = 'whm:sync-emails
                            {--instance= : Specific WHM instance name (default: all mail-role instances)}
                            {--with-disk : Include disk usage (slower)}
                            {--sync : Run synchronously (skip queue) — for first run / debug}';

    protected $description = 'Sync WHM email accounts from API → DB snapshot. Default: dispatches jobs to queue.';

    public function handle(WhmClient $whm): int
    {
        $instance = $this->option('instance');
        $withDisk = (bool) $this->option('with-disk');
        $runSync = (bool) $this->option('sync');

        $instances = $instance
            ? [$instance]
            : $whm->instancesByRole('mail');

        if (empty($instances)) {
            $this->warn('No WHM instances with role=mail found.');
            return self::SUCCESS;
        }

        foreach ($instances as $name) {
            $this->info("Dispatching sync for instance: {$name}");

            $job = new SyncWhmEmailsJob(
                instance: $name,
                payload: ['with_disk' => $withDisk],
                triggerSource: 'scheduled',
            );

            if ($runSync) {
                // Synchronous — log result inline
                try {
                    $job->handle();
                    $this->line("  ✓ Done synchronously");
                } catch (\Throwable $e) {
                    $this->error("  ✗ Failed: ".$e->getMessage());
                }
            } else {
                dispatch($job);
                $this->line("  → Queued");
            }
        }

        return self::SUCCESS;
    }
}
