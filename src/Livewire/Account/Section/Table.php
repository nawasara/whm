<?php

namespace Nawasara\Whm\Livewire\Account\Section;

use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Nawasara\Registry\Models\Asset;
use Nawasara\Whm\Livewire\Concerns\HasServerRole;
use Nawasara\Whm\Services\WhmClient;

class Table extends Component
{
    use HasServerRole;

    protected function serverRole(): string
    {
        return 'hosting';
    }

    #[Url(except: '')]
    public string $server = '';

    public string $search = '';
    public string $statusFilter = '';
    public string $packageFilter = '';

    // Form modal state (create)
    public ?string $editingUser = null;
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
    public ?array $detailAccount = null;

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
    public function serverOptions(): array
    {
        return collect($this->servers)
            ->mapWithKeys(fn ($s) => [$s => $s])
            ->all();
    }

    #[Computed]
    public function accounts(): array
    {
        if (! $this->client()->isConfigured()) {
            return [];
        }

        $accounts = $this->client()->getCachedAccounts();

        // Apply filters
        return collect($accounts)
            ->filter(function ($acct) {
                if ($this->search) {
                    $needle = strtolower($this->search);
                    $haystack = strtolower(($acct['user'] ?? '').' '.($acct['domain'] ?? '').' '.($acct['email'] ?? ''));
                    if (! str_contains($haystack, $needle)) {
                        return false;
                    }
                }

                if ($this->statusFilter) {
                    $suspended = ($acct['suspended'] ?? 0) == 1;
                    if ($this->statusFilter === 'active' && $suspended) return false;
                    if ($this->statusFilter === 'suspended' && ! $suspended) return false;
                }

                if ($this->packageFilter && ($acct['plan'] ?? '') !== $this->packageFilter) {
                    return false;
                }

                return true;
            })
            ->values()
            ->all();
    }

    #[Computed]
    public function packageOptions(): array
    {
        $packages = $this->client()->getCachedPackages();
        return collect($packages)
            ->pluck('name')
            ->filter()
            ->mapWithKeys(fn ($n) => [$n => $n])
            ->all();
    }

    #[Computed]
    public function assetMap()
    {
        $usernames = collect($this->accounts)->pluck('user')->filter()->all();
        if (empty($usernames)) {
            return collect();
        }

        return Asset::where('package_ref', 'whm')
            ->whereIn('external_id', $usernames)
            ->with(['opd:id,name,code', 'pic:id,name'])
            ->get()
            ->keyBy('external_id');
    }

    public function updatedServer(): void
    {
        unset($this->accounts, $this->packageOptions, $this->assetMap);
    }

    public function updatedSearch(): void
    {
        unset($this->accounts);
    }

    // ─── Detail ─────────────────────────────────────────

    public function openDetail(string $username): void
    {
        $detail = $this->client()->getAccount($username);
        $this->detailAccount = $detail;
        $this->dispatch('modal-open:whm-account-detail');
    }

    public function closeDetail(): void
    {
        $this->dispatch('modal-close:whm-account-detail');
        $this->detailAccount = null;
    }

    // ─── Create ─────────────────────────────────────────

    #[On('openCreateAccount')]
    public function openCreate(): void
    {
        Gate::authorize('whm.account.create');

        $this->reset([
            'editingUser', 'formUsername', 'formDomain', 'formPassword',
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

        $result = $this->client()->createAccount([
            'username' => $this->formUsername,
            'domain' => $this->formDomain,
            'password' => $this->formPassword,
            'contactemail' => $this->formEmail,
            'plan' => $this->formPackage,
        ]);

        if (! $result['success']) {
            toaster_error('Gagal membuat akun: '.$result['message']);
            return;
        }

        // Create registry asset
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

        $this->client()->flushCache();
        unset($this->accounts, $this->assetMap);
        toaster_success('Akun cPanel berhasil dibuat');
        $this->dispatch('modal-close:whm-account-form');
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

        $ok = $this->client()->suspendAccount($this->suspendUsername, $this->suspendReason ?: null);

        if ($ok) {
            $this->client()->flushCache();
            unset($this->accounts);
            toaster_success("Akun {$this->suspendUsername} berhasil di-suspend");
            $this->dispatch('modal-close:whm-suspend');
        } else {
            toaster_error('Gagal suspend akun');
        }
    }

    public function unsuspend(string $username): void
    {
        Gate::authorize('whm.account.suspend');

        if ($this->client()->unsuspendAccount($username)) {
            $this->client()->flushCache();
            unset($this->accounts);
            toaster_success("Akun {$username} berhasil di-unsuspend");
        } else {
            toaster_error('Gagal unsuspend akun');
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

        if ($this->client()->changePassword($this->pwUsername, $this->pwNewPassword)) {
            toaster_success("Password {$this->pwUsername} berhasil diubah");
            $this->dispatch('modal-close:whm-password');
        } else {
            toaster_error('Gagal mengubah password');
        }
    }

    // ─── Terminate ──────────────────────────────────────

    public function terminate(string $username): void
    {
        Gate::authorize('whm.account.terminate');

        if ($this->client()->terminateAccount($username)) {
            // Mark asset as inactive (don't delete — preserve history)
            Asset::where('package_ref', 'whm')
                ->where('external_id', $username)
                ->update(['status' => 'inactive']);

            $this->client()->flushCache();
            unset($this->accounts, $this->assetMap);
            toaster_success("Akun {$username} berhasil dihapus");
        } else {
            toaster_error('Gagal menghapus akun');
        }
    }

    public function refreshAccounts(): void
    {
        $this->client()->flushCache();
        unset($this->accounts, $this->assetMap, $this->packageOptions);
        toaster_success('Daftar akun di-refresh');
    }

    public function render()
    {
        return view('nawasara-whm::livewire.pages.account.section.table');
    }
}
