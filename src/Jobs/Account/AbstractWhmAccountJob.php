<?php

namespace Nawasara\Whm\Jobs\Account;

use Nawasara\Sync\Jobs\AbstractSyncJob;
use Nawasara\Whm\Models\WhmAccount;
use Nawasara\Whm\Services\WhmClient;

abstract class AbstractWhmAccountJob extends AbstractSyncJob
{
    public int $timeout = 90;

    protected function service(): string
    {
        return 'whm';
    }

    protected function targetType(): ?string
    {
        return 'WhmAccount';
    }

    protected function targetId(): ?string
    {
        return $this->payload['username'] ?? null;
    }

    protected function client(): WhmClient
    {
        return app(WhmClient::class)->forInstance($this->instance);
    }

    protected function record(): ?WhmAccount
    {
        $username = $this->payload['username'] ?? null;
        if (! $username) {
            return null;
        }
        return WhmAccount::where('instance', $this->instance)
            ->where('username', $username)
            ->first();
    }

    protected function currentExternalHash(): ?string
    {
        return $this->record()?->content_hash;
    }

    protected function refreshLocalRecord(WhmAccount $record, array $changes = []): void
    {
        if (! empty($changes)) {
            $record->fill($changes);
        }
        $record->content_hash = $record->computeContentHash();
        $record->markSynced();
        $record->save();
    }
}
