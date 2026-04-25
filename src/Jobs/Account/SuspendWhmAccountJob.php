<?php

namespace Nawasara\Whm\Jobs\Account;

class SuspendWhmAccountJob extends AbstractWhmAccountJob
{
    protected function action(): string
    {
        return 'suspend';
    }

    protected function execute(): array
    {
        $username = $this->payload['username'];
        $reason = $this->payload['reason'] ?? null;

        $record = $this->record();
        if (! $record) {
            throw new \RuntimeException("Local record not found: {$username}");
        }

        $ok = $this->client()->suspendAccount($username, $reason);
        if (! $ok) {
            throw new \RuntimeException("WHM rejected suspendacct for {$username}");
        }

        $this->refreshLocalRecord($record, [
            'suspended' => true,
            'suspend_reason' => $reason,
        ]);

        return ['username' => $username, 'reason' => $reason];
    }
}
