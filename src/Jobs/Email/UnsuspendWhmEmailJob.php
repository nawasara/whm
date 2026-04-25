<?php

namespace Nawasara\Whm\Jobs\Email;

class UnsuspendWhmEmailJob extends AbstractWhmEmailJob
{
    protected function action(): string
    {
        return 'unsuspend';
    }

    protected function execute(): array
    {
        $email = $this->payload['email'];

        $record = $this->record();
        if (! $record) {
            throw new \RuntimeException("Local record not found: {$email}");
        }

        $whm = $this->client();
        $cpanelUser = $whm->defaultCpanelUser();

        $ok = $whm->unsuspendEmailAccount($cpanelUser, $email);
        if (! $ok) {
            throw new \RuntimeException("WHM rejected unsuspend for {$email}");
        }

        $this->refreshLocalRecord($record, [
            'suspended_login' => false,
            'suspended_incoming' => false,
        ]);

        return ['email' => $email];
    }
}
