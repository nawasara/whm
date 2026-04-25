<?php

namespace Nawasara\Whm\Repositories;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Nawasara\Sync\Contracts\SyncedRepository;
use Nawasara\Sync\Models\SyncJob;
use Nawasara\Whm\Jobs\Account\ChangeWhmAccountPackageJob;
use Nawasara\Whm\Jobs\Account\ChangeWhmAccountPasswordJob;
use Nawasara\Whm\Jobs\Account\CreateWhmAccountJob;
use Nawasara\Whm\Jobs\Account\SuspendWhmAccountJob;
use Nawasara\Whm\Jobs\Account\SyncWhmAccountsJob;
use Nawasara\Whm\Jobs\Account\TerminateWhmAccountJob;
use Nawasara\Whm\Jobs\Account\UnsuspendWhmAccountJob;
use Nawasara\Whm\Models\WhmAccount;

class WhmAccountRepository implements SyncedRepository
{
    public function __construct(public ?string $instance = null)
    {
    }

    public function forInstance(?string $instance): static
    {
        return new static($instance ?: null);
    }

    public function list(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($filters)->orderBy('username')->paginate($perPage);
    }

    public function find(string|int $id): ?Model
    {
        if (is_numeric($id)) {
            return WhmAccount::find($id);
        }
        return WhmAccount::forInstance($this->instance)->where('username', $id)->first();
    }

    public function all(array $filters = []): Collection
    {
        return $this->query($filters)->orderBy('username')->get();
    }

    public function create(array $data): ?SyncJob
    {
        CreateWhmAccountJob::dispatch(
            instance: $this->instance,
            payload: $data,
        );

        return $this->latestJobFor('create', $data['username'] ?? null);
    }

    public function update(string|int $id, array $data): ?SyncJob
    {
        $account = $this->find($id);
        if (! $account) {
            throw new \InvalidArgumentException("Account not found: {$id}");
        }

        $expectedHash = $account->content_hash;
        $account->markPending(WhmAccount::SYNC_PENDING_UPDATE);

        if (isset($data['package'])) {
            ChangeWhmAccountPackageJob::dispatch(
                instance: $this->instance,
                payload: ['username' => $account->username, 'package' => $data['package']],
                expectedHash: $expectedHash,
            );
        }

        if (isset($data['password'])) {
            ChangeWhmAccountPasswordJob::dispatch(
                instance: $this->instance,
                payload: ['username' => $account->username, 'password' => $data['password']],
                expectedHash: $expectedHash,
            );
        }

        if (isset($data['suspend'])) {
            SuspendWhmAccountJob::dispatch(
                instance: $this->instance,
                payload: [
                    'username' => $account->username,
                    'reason' => $data['suspend_reason'] ?? null,
                ],
                expectedHash: $expectedHash,
            );
        }

        return $this->latestJobFor('update', $account->username);
    }

    public function unsuspend(string $username): ?SyncJob
    {
        $account = $this->find($username);
        if (! $account) {
            throw new \InvalidArgumentException("Account not found: {$username}");
        }
        $account->markPending(WhmAccount::SYNC_PENDING_UPDATE);

        UnsuspendWhmAccountJob::dispatch(
            instance: $this->instance,
            payload: ['username' => $username],
            expectedHash: $account->content_hash,
        );

        return $this->latestJobFor('unsuspend', $username);
    }

    public function delete(string|int $id): ?SyncJob
    {
        $account = $this->find($id);
        if (! $account) {
            throw new \InvalidArgumentException("Account not found: {$id}");
        }

        $account->markPending(WhmAccount::SYNC_PENDING_DELETE);

        TerminateWhmAccountJob::dispatch(
            instance: $this->instance,
            payload: ['username' => $account->username],
            expectedHash: $account->content_hash,
        );

        return $this->latestJobFor('terminate', $account->username);
    }

    public function syncNow(): ?SyncJob
    {
        SyncWhmAccountsJob::dispatch(
            instance: $this->instance,
            triggerSource: 'manual',
        );

        return $this->latestJobFor('sync_accounts', null);
    }

    public function lastSyncedAt(): ?Carbon
    {
        $latest = WhmAccount::forInstance($this->instance)
            ->whereNotNull('last_synced_at')
            ->orderByDesc('last_synced_at')
            ->value('last_synced_at');

        return $latest ? Carbon::parse($latest) : null;
    }

    protected function query(array $filters = [])
    {
        return WhmAccount::query()
            ->forInstance($filters['instance'] ?? $this->instance)
            ->search($filters['search'] ?? null)
            ->status($filters['status'] ?? null)
            ->plan($filters['plan'] ?? null);
    }

    protected function latestJobFor(string $action, ?string $targetId): ?SyncJob
    {
        $q = SyncJob::query()
            ->where('service', 'whm')
            ->where('action', $action)
            ->latest('id');

        if ($targetId) {
            $q->where('target_id', $targetId);
        }

        return $q->first();
    }
}
