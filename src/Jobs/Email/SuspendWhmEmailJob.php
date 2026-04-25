<?php

namespace Nawasara\Whm\Jobs\Email;

class SuspendWhmEmailJob extends AbstractWhmEmailJob
{
    protected function action(): string
    {
        return 'suspend';
    }

    protected function execute(): array
    {
        $email = $this->payload['email'];
        $login = (bool) ($this->payload['login'] ?? true);
        $incoming = (bool) ($this->payload['incoming'] ?? true);

        $record = $this->record();
        if (! $record) {
            throw new \RuntimeException("Local record not found: {$email}");
        }

        $type = match (true) {
            $login && $incoming => 'both',
            $login => 'login',
            $incoming => 'incoming',
            default => null,
        };

        if ($type === null) {
            return ['email' => $email, 'noop' => true];
        }

        $whm = $this->client();
        $cpanelUser = $whm->defaultCpanelUser();

        $ok = $whm->suspendEmailAccount($cpanelUser, $email, $type);
        if (! $ok) {
            throw new \RuntimeException("WHM rejected suspend for {$email}");
        }

        $this->refreshLocalRecord($record, [
            'suspended_login' => $login,
            'suspended_incoming' => $incoming,
        ]);

        return ['email' => $email, 'type' => $type];
    }
}
