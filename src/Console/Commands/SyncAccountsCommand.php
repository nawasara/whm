<?php

namespace Nawasara\Whm\Console\Commands;

use Illuminate\Console\Command;
use Nawasara\Whm\Services\AccountRegistrySync;

class SyncAccountsCommand extends Command
{
    protected $signature = 'whm:sync-accounts';

    protected $description = 'Sync WHM/cPanel accounts into the registry as hosting_account assets.';

    public function handle(AccountRegistrySync $sync): int
    {
        $this->info('Syncing WHM accounts...');

        $stats = $sync->sync();

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

        if ($stats['created'] > 0) {
            $this->warn("→ {$stats['created']} akun baru terdeteksi (perlu review OPD/PIC)");
        }
        if ($stats['deactivated'] > 0) {
            $this->warn("→ {$stats['deactivated']} akun hilang dari WHM (mark inactive)");
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
