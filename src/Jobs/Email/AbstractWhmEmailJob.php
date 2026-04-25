<?php

namespace Nawasara\Whm\Jobs\Email;

use Nawasara\Sync\Jobs\AbstractSyncJob;
use Nawasara\Whm\Models\WhmEmailAccount;
use Nawasara\Whm\Services\WhmClient;

abstract class AbstractWhmEmailJob extends AbstractSyncJob
{
    public int $timeout = 60;

    protected function service(): string
    {
        return 'whm';
    }

    protected function targetType(): ?string
    {
        return 'WhmEmailAccount';
    }

    protected function targetId(): ?string
    {
        return $this->payload['email'] ?? null;
    }

    protected function client(): WhmClient
    {
        return app(WhmClient::class)->forInstance($this->instance);
    }

    protected function record(): ?WhmEmailAccount
    {
        $email = $this->payload['email'] ?? null;
        if (! $email) {
            return null;
        }
        return WhmEmailAccount::where('instance', $this->instance)
            ->where('email', $email)
            ->first();
    }

    /**
     * Hash sekarang dari snapshot DB (canonical state at time of dispatch).
     * Override kalau perlu fetch dari WHM langsung (lebih akurat tapi lambat).
     */
    protected function currentExternalHash(): ?string
    {
        return $this->record()?->content_hash;
    }

    /** Helper untuk update status setelah sukses operasi. */
    protected function refreshLocalRecord(WhmEmailAccount $record, array $changes = []): void
    {
        if (! empty($changes)) {
            $record->fill($changes);
        }
        $record->content_hash = $record->computeContentHash();
        $record->markSynced();
        $record->save();
    }
}
