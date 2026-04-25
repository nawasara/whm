<?php

namespace Nawasara\Whm\Jobs\Account;

class TerminateWhmAccountJob extends AbstractWhmAccountJob
{
    protected function action(): string
    {
        return 'terminate';
    }

    protected function execute(): array
    {
        $username = $this->payload['username'];

        $record = $this->record();
        if (! $record) {
            return ['username' => $username, 'noop' => true];
        }

        $ok = $this->client()->terminateAccount($username);
        if (! $ok) {
            throw new \RuntimeException("WHM rejected removeacct for {$username}");
        }

        $record->delete();

        return ['username' => $username, 'deleted' => true];
    }
}
