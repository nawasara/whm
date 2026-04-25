<?php

namespace Nawasara\Whm\Jobs\Account;

class ChangeWhmAccountPackageJob extends AbstractWhmAccountJob
{
    protected function action(): string
    {
        return 'update_package';
    }

    /** Quota change ringan — last-write-wins OK */
    protected function shouldCheckConflict(): bool
    {
        return false;
    }

    protected function execute(): array
    {
        $username = $this->payload['username'];
        $package = $this->payload['package'];

        $record = $this->record();
        if (! $record) {
            throw new \RuntimeException("Local record not found: {$username}");
        }

        $ok = $this->client()->changePackage($username, $package);
        if (! $ok) {
            throw new \RuntimeException("WHM rejected changepackage for {$username}");
        }

        // Quota berubah → trigger sync untuk pull limit baru
        SyncWhmAccountsJob::dispatch(
            instance: $this->instance,
            triggerSource: 'event',
        );

        $this->refreshLocalRecord($record, ['plan' => $package]);

        return ['username' => $username, 'package' => $package];
    }
}
