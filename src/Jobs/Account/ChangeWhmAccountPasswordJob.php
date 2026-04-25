<?php

namespace Nawasara\Whm\Jobs\Account;

class ChangeWhmAccountPasswordJob extends AbstractWhmAccountJob
{
    protected function action(): string
    {
        return 'update_password';
    }

    protected function execute(): array
    {
        $username = $this->payload['username'];
        $password = $this->payload['password'];

        $record = $this->record();
        if (! $record) {
            throw new \RuntimeException("Local record not found: {$username}");
        }

        $ok = $this->client()->changePassword($username, $password);
        if (! $ok) {
            throw new \RuntimeException("WHM rejected passwd for {$username}");
        }

        $this->refreshLocalRecord($record);

        return ['username' => $username];
    }
}
