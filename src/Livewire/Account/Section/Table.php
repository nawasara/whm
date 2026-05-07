<?php

namespace Nawasara\Whm\Livewire\Account\Section;

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nawasara\Registry\Models\Asset;
use Nawasara\Ui\Livewire\Concerns\HasBrowserToast;
use Nawasara\Ui\Livewire\Concerns\HasExport;
use Nawasara\Whm\Livewire\Concerns\HasServerRole;
use Nawasara\Whm\Models\WhmAccount;
use Nawasara\Whm\Repositories\WhmAccountRepository;
use Nawasara\Whm\Services\WhmClient;

class Table extends Component
{
    use HasBrowserToast;
    use HasExport;
    use HasServerRole;
    use WithPagination;

    protected function serverRole(): string
    {
        return 'hosting';
    }

    #[Url(except: '')]
    public string $server = '';

    public string $search = '';

    /**
     * Multi-select filters (filter-panel array semantics). Empty array
     * == no filter. Underlying scopes are polymorphic.
     *
     * @var array<int, string>
     */
    public array $statusFilter = [];

    /** @var array<int, string> */
    public array $packageFilter = [];

    public int $perPage = 25;

    // Form modal state (create)
    public string $formUsername = '';
    public string $formDomain = '';
    public string $formPassword = '';
    public string $formEmail = '';
    public string $formPackage = '';
    public $formOpdId = '';
    public $formPicId = '';

    // Password change modal
    public string $pwUsername = '';
    public string $pwNewPassword = '';

    // Suspend modal
    public string $suspendUsername = '';
    public string $suspendReason = '';

    // Detail modal
    public ?int $detailId = null;

    // Bulk selection
    public array $selected = [];
    public bool $selectAll = false;

    // Bulk suspend modal
    public string $bulkSuspendReason = '';

    protected WhmClient $whm;

    public function boot(WhmClient $whm)
    {
        $this->whm = $whm;
    }

    public function mount(): void
    {
        if (! $this->server) {
            $this->server = $this->defaultInstance($this->whm) ?? '';
        }
    }

    protected function repo(): WhmAccountRepository
    {
        return new WhmAccountRepository($this->server ?: null);
    }

    protected function client(): WhmClient
    {
        return $this->server ? $this->whm->forInstance($this->server) : $this->whm;
    }

    #[Computed]
    public function servers(): array
    {
        return $this->rolledInstances($this->whm);
    }

    #[Computed]
    public function accounts()
    {
        return $this->repo()->list([
            'search' => $this->search ?: null,
            // Empty arrays pass through; polymorphic scopes are no-op on empty.
            'status' => $this->statusFilter,
            'plan' => $this->packageFilter,
        ], $this->perPage);
    }

    #[Computed]
    public function packageOptions(): array
    {
        return WhmAccount::forInstance($this->server)
            ->select('plan')
            ->distinct()
            ->whereNotNull('plan')
            ->orderBy('plan')
            ->pluck('plan', 'plan')
            ->all();
    }

    #[Computed]
    public function lastSyncedAt(): ?string
    {
        $when = $this->repo()->lastSyncedAt();
        return $when ? $when->diffForHumans() : null;
    }

    #[Computed]
    public function pendingCount(): int
    {
        return WhmAccount::forInstance($this->server)
            ->pendingSync()
            ->count();
    }

    #[Computed]
    public function assetMap()
    {
        // Tetap pakai registry asset linking
        $usernames = $this->accounts->pluck('username')->filter()->all();
        if (empty($usernames)) {
            return collect();
        }

        return Asset::where('package_ref', 'whm')
            ->whereIn('external_id', $usernames)
            ->with(['opd:id,name,code', 'pic:id,name'])
            ->get()
            ->keyBy('external_id');
    }

    public function updatedServer(): void { $this->resetPage(); $this->resetSelection(); }
    public function updatedSearch(): void { $this->resetPage(); $this->resetSelection(); }
    public function updatedStatusFilter(): void { $this->resetPage(); $this->resetSelection(); }
    public function updatedPackageFilter(): void { $this->resetPage(); $this->resetSelection(); }

    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value ? $this->accounts->pluck('id')->map(fn ($id) => (string) $id)->all() : [];
    }

    public function resetSelection(): void
    {
        $this->selected = [];
        $this->selectAll = false;
    }

    // ─── Sync ───────────────────────────────────────────

    public function refreshAccounts(): void
    {
        Gate::authorize('whm.account.view');

        $this->repo()->syncNow();
        $this->toastSuccess('Sync dispatched. Data akan refresh dalam beberapa detik.');
    }

    // ─── Detail ─────────────────────────────────────────

    public function openDetail(int $id): void
    {
        $this->detailId = $id;
        $this->dispatch('modal-open:whm-account-detail');
    }

    #[Computed]
    public function detail(): ?WhmAccount
    {
        return $this->detailId ? WhmAccount::find($this->detailId) : null;
    }

    public function closeDetail(): void
    {
        $this->dispatch('modal-close:whm-account-detail');
        $this->detailId = null;
    }

    // ─── Create ─────────────────────────────────────────

    #[On('openCreateAccount')]
    public function openCreate(): void
    {
        Gate::authorize('whm.account.create');

        $this->reset([
            'formUsername', 'formDomain', 'formPassword',
            'formEmail', 'formPackage', 'formOpdId', 'formPicId',
        ]);
        $this->dispatch('modal-open:whm-account-form');
    }

    public function saveAccount(): void
    {
        Gate::authorize('whm.account.create');

        $this->validate([
            'formUsername' => 'required|alpha_num|max:16',
            'formDomain' => 'required|string|max:255',
            'formPassword' => 'required|min:8',
            'formEmail' => 'required|email',
            'formPackage' => 'required|string',
        ]);

        try {
            $this->repo()->create([
                'username' => $this->formUsername,
                'domain' => $this->formDomain,
                'password' => $this->formPassword,
                'email' => $this->formEmail,
                'plan' => $this->formPackage,
            ]);

            // Create registry asset langsung (sebelum sync selesai)
            Asset::updateOrCreate(
                ['package_ref' => 'whm', 'external_id' => $this->formUsername],
                [
                    'type' => 'hosting_account',
                    'identifier' => $this->formDomain,
                    'opd_id' => $this->formOpdId ?: null,
                    'pic_id' => $this->formPicId ?: null,
                    'status' => 'active',
                    'registered_at' => now(),
                    'notes' => 'WHM account: '.$this->formUsername.' on '.($this->server ?: 'default'),
                ]
            );

            $this->toastSuccess("Akun {$this->formUsername} sedang dibuat. Cek status di Sync Jobs.");
            $this->dispatch('modal-close:whm-account-form');
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    // ─── Suspend / Unsuspend ────────────────────────────

    public function openSuspend(string $username): void
    {
        Gate::authorize('whm.account.suspend');

        $this->suspendUsername = $username;
        $this->suspendReason = '';
        $this->dispatch('modal-open:whm-suspend');
    }

    public function doSuspend(): void
    {
        Gate::authorize('whm.account.suspend');

        try {
            $this->repo()->update($this->suspendUsername, [
                'suspend' => true,
                'suspend_reason' => $this->suspendReason ?: null,
            ]);
            $this->toastSuccess("Suspend dispatched untuk {$this->suspendUsername}");
            $this->dispatch('modal-close:whm-suspend');
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function unsuspend(string $username): void
    {
        Gate::authorize('whm.account.suspend');

        try {
            $this->repo()->unsuspend($username);
            $this->toastSuccess("Unsuspend dispatched untuk {$username}");
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    // ─── Password ───────────────────────────────────────

    public function openChangePassword(string $username): void
    {
        Gate::authorize('whm.account.manage');

        $this->pwUsername = $username;
        $this->pwNewPassword = '';
        $this->dispatch('modal-open:whm-password');
    }

    public function doChangePassword(): void
    {
        Gate::authorize('whm.account.manage');

        $this->validate(['pwNewPassword' => 'required|min:8']);

        try {
            $this->repo()->update($this->pwUsername, ['password' => $this->pwNewPassword]);
            $this->toastSuccess("Password {$this->pwUsername} sedang di-update");
            $this->dispatch('modal-close:whm-password');
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    // ─── Terminate ──────────────────────────────────────

    public function terminate(string $username): void
    {
        Gate::authorize('whm.account.terminate');

        try {
            $this->repo()->delete($username);

            // Mark asset inactive (preserve history)
            Asset::where('package_ref', 'whm')
                ->where('external_id', $username)
                ->update(['status' => 'inactive']);

            $this->toastSuccess("Terminate dispatched untuk {$username}");
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    // ─── Bulk Operations ────────────────────────────────

    protected function selectedUsernames(): array
    {
        if (empty($this->selected)) {
            return [];
        }

        return WhmAccount::forInstance($this->server)
            ->whereIn('id', $this->selected)
            ->pluck('username')
            ->all();
    }

    public function openBulkSuspend(): void
    {
        Gate::authorize('whm.account.suspend');

        if (empty($this->selected)) {
            $this->toastError('Tidak ada akun yang dipilih.');
            return;
        }

        $this->bulkSuspendReason = '';
        $this->dispatch('modal-open:whm-bulk-suspend');
    }

    public function doBulkSuspend(): void
    {
        Gate::authorize('whm.account.suspend');

        $usernames = $this->selectedUsernames();
        $count = 0;
        foreach ($usernames as $username) {
            try {
                $this->repo()->update($username, [
                    'suspend' => true,
                    'suspend_reason' => $this->bulkSuspendReason ?: null,
                ]);
                $count++;
            } catch (\Throwable $e) {
                // Skip failures
            }
        }

        $this->toastSuccess("Suspend dispatched untuk {$count} akun.");
        $this->dispatch('modal-close:whm-bulk-suspend');
        $this->resetSelection();
    }

    public function bulkUnsuspend(): void
    {
        Gate::authorize('whm.account.suspend');

        $usernames = $this->selectedUsernames();
        if (empty($usernames)) {
            $this->toastError('Tidak ada akun yang dipilih.');
            return;
        }

        $count = 0;
        foreach ($usernames as $username) {
            try {
                $this->repo()->unsuspend($username);
                $count++;
            } catch (\Throwable $e) {
                // Skip failures
            }
        }

        $this->toastSuccess("Unsuspend dispatched untuk {$count} akun.");
        $this->resetSelection();
    }

    /**
     * Export filename base — timestamp + extension appended by HasExport.
     * Includes the active server slug so multi-instance setups don't clash.
     */
    protected function exportFilename(): string
    {
        $slug = $this->server ?: 'all';
        return 'whm-accounts-'.preg_replace('/[^a-z0-9-]+/i', '-', $slug);
    }

    /**
     * Export FULL account list for the active server (no filter) per spec.
     * Includes registry-mapped OPD/PIC for downstream audit.
     */
    protected function exportData(): iterable
    {
        $accounts = WhmAccount::forInstance($this->server)
            ->orderBy('username')
            ->get();

        // Pull asset map keyed by username so we don't N+1 over the row map.
        $assetMap = Asset::query()
            ->where('package_ref', 'whm')
            ->whereIn('external_id', $accounts->pluck('username')->filter()->all())
            ->with(['opd:id,name,code', 'pic:id,name'])
            ->get()
            ->keyBy('external_id');

        return $accounts->map(function (WhmAccount $a) use ($assetMap) {
            $asset = $assetMap[$a->username] ?? null;
            return [
                'Server' => $a->instance,
                'Username' => $a->username,
                'Domain' => $a->domain,
                'Email' => $a->email,
                'Plan' => $a->plan,
                'Status' => $a->suspended ? 'Suspended' : 'Active',
                'Suspend Reason' => $a->suspend_reason,
                'IP' => $a->ip,
                'Owner' => $a->owner,
                'Disk Used MB' => $a->disk_used_mb,
                'Disk Limit MB' => $a->disk_limit_mb,
                'Bandwidth Used MB' => $a->bandwidth_used_mb,
                'Bandwidth Limit MB' => $a->bandwidth_limit_mb,
                'Inodes Used' => $a->inodes_used,
                'OPD' => $asset?->opd?->name,
                'PIC' => $asset?->pic?->name,
                'WHM Created' => optional($a->start_date)->format('Y-m-d'),
                'Last Synced' => optional($a->last_synced_at)->format('Y-m-d H:i'),
            ];
        });
    }

    public function render()
    {
        return view('nawasara-whm::livewire.pages.account.section.table');
    }
}
