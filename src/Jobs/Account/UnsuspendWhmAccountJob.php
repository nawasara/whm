<?php

namespace Nawasara\Whm\Jobs\Account;

class UnsuspendWhmAccountJob extends AbstractWhmAccountJob
{
    protected function action(): string
    {
        return 'unsuspend';
    }

    protected function execute(): array
    {
        $username = $this->payload['username'];

        $record = $this->record();
        if (! $record) {
            throw new \RuntimeException("Local record not found: {$username}");
        }

        $ok = $this->client()->unsuspendAccount($username);
        if (! $ok) {
            throw new \RuntimeException("WHM rejected unsuspendacct for {$username}");
        }

        $this->refreshLocalRecord($record, [
            'suspended' => false,
            'suspend_reason' => null,
        ]);

        return ['username' => $username];
    }
}
