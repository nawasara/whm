<?php

namespace Nawasara\Whm\Jobs\Account;

class CreateWhmAccountJob extends AbstractWhmAccountJob
{
    protected function action(): string
    {
        return 'create';
    }

    protected function shouldCheckConflict(): bool
    {
        return false;
    }

    protected function execute(): array
    {
        $username = $this->payload['username'];

        $result = $this->client()->createAccount([
            'username' => $username,
            'domain' => $this->payload['domain'],
            'password' => $this->payload['password'],
            'contactemail' => $this->payload['email'] ?? null,
            'plan' => $this->payload['plan'],
        ]);

        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException($result['message'] ?? 'WHM rejected createacct');
        }

        // Trigger background re-sync untuk pull data lengkap account baru
        SyncWhmAccountsJob::dispatch(
            instance: $this->instance,
            triggerSource: 'event',
        );

        return ['username' => $username];
    }
}
