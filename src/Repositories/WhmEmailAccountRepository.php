<?php

namespace Nawasara\Whm\Repositories;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Nawasara\Sync\Contracts\SyncedRepository;
use Nawasara\Sync\Models\SyncJob;
use Nawasara\Whm\Jobs\Email\ChangeWhmEmailPasswordJob;
use Nawasara\Whm\Jobs\Email\ChangeWhmEmailQuotaJob;
use Nawasara\Whm\Jobs\Email\CreateWhmEmailJob;
use Nawasara\Whm\Jobs\Email\DeleteWhmEmailJob;
use Nawasara\Whm\Jobs\Email\SuspendWhmEmailJob;
use Nawasara\Whm\Jobs\Email\SyncWhmEmailsJob;
use Nawasara\Whm\Jobs\Email\UnsuspendWhmEmailJob;
use Nawasara\Whm\Models\WhmEmailAccount;

class WhmEmailAccountRepository implements SyncedRepository
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
        return $this->query($filters)->orderBy('email')->paginate($perPage);
    }

    public function find(string|int $id): ?Model
    {
        // $id bisa berupa email string atau integer PK
        if (is_numeric($id)) {
            return WhmEmailAccount::find($id);
        }
        return WhmEmailAccount::forInstance($this->instance)->where('email', $id)->first();
    }

    public function all(array $filters = []): Collection
    {
        return $this->query($filters)->orderBy('email')->get();
    }

    public function create(array $data): ?SyncJob
    {
        // Optimistic UI: tidak buat row di DB sampai job berhasil — biar tidak duplicate.
        // Atau bisa juga insert dengan sync_status=pending_create dan job hapus on failure.
        $job = CreateWhmEmailJob::dispatch(
            instance: $this->instance,
            payload: $data,
        );

        return $this->latestJobFor('create', $data['email'] ?? null);
    }

    public function update(string|int $id, array $data): ?SyncJob
    {
        $email = $this->find($id);
        if (! $email) {
            throw new \InvalidArgumentException("Email account not found: {$id}");
        }

        // Capture hash for conflict detection
        $expectedHash = $email->content_hash;

        // Optimistic update di DB
        $email->markPending(WhmEmailAccount::SYNC_PENDING_UPDATE);

        // Dispatch job appropriate per data field changed
        if (isset($data['quota_mb'])) {
            ChangeWhmEmailQuotaJob::dispatch(
                instance: $this->instance,
                payload: ['email' => $email->email, 'quota_mb' => $data['quota_mb']],
                expectedHash: $expectedHash,
            );
        }

        if (isset($data['password'])) {
            ChangeWhmEmailPasswordJob::dispatch(
                instance: $this->instance,
                payload: ['email' => $email->email, 'password' => $data['password']],
                expectedHash: $expectedHash,
            );
        }

        if (isset($data['suspend_login']) || isset($data['suspend_incoming'])) {
            SuspendWhmEmailJob::dispatch(
                instance: $this->instance,
                payload: [
                    'email' => $email->email,
                    'login' => $data['suspend_login'] ?? $email->suspended_login,
                    'incoming' => $data['suspend_incoming'] ?? $email->suspended_incoming,
                ],
                expectedHash: $expectedHash,
            );
        }

        return $this->latestJobFor('update', $email->email);
    }

    public function unsuspend(string $email): ?SyncJob
    {
        $record = $this->find($email);
        if (! $record) {
            throw new \InvalidArgumentException("Email not found: {$email}");
        }
        $record->markPending(WhmEmailAccount::SYNC_PENDING_UPDATE);

        UnsuspendWhmEmailJob::dispatch(
            instance: $this->instance,
            payload: ['email' => $email],
            expectedHash: $record->content_hash,
        );

        return $this->latestJobFor('unsuspend', $email);
    }

    public function delete(string|int $id): ?SyncJob
    {
        $email = $this->find($id);
        if (! $email) {
            throw new \InvalidArgumentException("Email not found: {$id}");
        }

        $email->markPending(WhmEmailAccount::SYNC_PENDING_DELETE);

        DeleteWhmEmailJob::dispatch(
            instance: $this->instance,
            payload: ['email' => $email->email],
            expectedHash: $email->content_hash,
        );

        return $this->latestJobFor('delete', $email->email);
    }

    public function syncNow(): ?SyncJob
    {
        SyncWhmEmailsJob::dispatch(
            instance: $this->instance,
            triggerSource: 'manual',
        );

        return $this->latestJobFor('sync', null);
    }

    public function lastSyncedAt(): ?Carbon
    {
        $latest = WhmEmailAccount::forInstance($this->instance)
            ->whereNotNull('last_synced_at')
            ->orderByDesc('last_synced_at')
            ->value('last_synced_at');

        return $latest ? Carbon::parse($latest) : null;
    }

    /** Build base query dengan filter umum */
    protected function query(array $filters = [])
    {
        return WhmEmailAccount::query()
            ->forInstance($filters['instance'] ?? $this->instance)
            ->search($filters['search'] ?? null)
            ->status($filters['status'] ?? null)
            ->when($filters['domain'] ?? null, fn ($q, $d) => $q->where('domain', $d));
    }

    /**
     * Return the most recently created sync job for this action+target.
     * Returns null kalau tidak ada — caller boleh pakai sebagai informational only.
     */
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
