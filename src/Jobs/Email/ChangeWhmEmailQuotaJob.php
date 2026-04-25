<?php

namespace Nawasara\Whm\Jobs\Email;

class ChangeWhmEmailQuotaJob extends AbstractWhmEmailJob
{
    protected function action(): string
    {
        return 'update_quota';
    }

    /** Quota change tidak destructive — last-write-wins OK. */
    protected function shouldCheckConflict(): bool
    {
        return false;
    }

    protected function execute(): array
    {
        $email = $this->payload['email'];
        $quotaMb = (int) $this->payload['quota_mb'];

        $record = $this->record();
        if (! $record) {
            throw new \RuntimeException("Local record not found: {$email}");
        }

        $whm = $this->client();
        $cpanelUser = $whm->defaultCpanelUser();

        $ok = $whm->changeEmailQuota($cpanelUser, $email, $quotaMb);
        if (! $ok) {
            throw new \RuntimeException("WHM rejected edit_pop_quota for {$email}");
        }

        $this->refreshLocalRecord($record, [
            'quota_mb' => $quotaMb ?: null,
        ]);

        return ['email' => $email, 'quota_mb' => $quotaMb];
    }
}
