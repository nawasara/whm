<?php

namespace Nawasara\Whm\Livewire\Usage\Section;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nawasara\Whm\Livewire\Concerns\HasServerRole;
use Nawasara\Whm\Models\WhmAccount;
use Nawasara\Whm\Repositories\WhmAccountRepository;
use Nawasara\Whm\Services\WhmClient;

/**
 * Read-only usage dashboard. Reads from DB snapshot — no API calls.
 */
class Dashboard extends Component
{
    use HasServerRole;

    protected function serverRole(): string
    {
        return 'hosting';
    }

    #[Url(except: '')]
    public string $server = '';

    public string $stateFilter = '';

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

    #[Computed]
    public function servers(): array
    {
        return $this->rolledInstances($this->whm);
    }

    #[Computed]
    public function lastSyncedAt(): ?string
    {
        $when = $this->repo()->lastSyncedAt();
        return $when ? $when->diffForHumans() : null;
    }

    /**
     * Full unfiltered dataset untuk server aktif. Single source of truth
     * untuk summary stat-card (yang harus tampil count keseluruhan) maupun
     * usageList table (yang boleh di-filter).
     *
     * Sengaja dipisah dari usageList: kalau summary hitung dari usageList
     * yang sudah di-filter, klik salah satu stat-card bikin angka di
     * stat-card lain ikut nol — itu bug yang membingungkan user.
     */
    #[Computed]
    public function allRows(): array
    {
        $accounts = WhmAccount::forInstance($this->server)->get();

        return $accounts->map(function (WhmAccount $acct) {
            $state = $acct->usageState();
            return [
                'user' => $acct->username,
                'domain' => $acct->domain,
                'plan' => $acct->plan,
                'disk_used' => $acct->humanized['diskused'] ?? ($acct->disk_used_mb !== null ? round($acct->disk_used_mb, 1).' MB' : '-'),
                'disk_limit' => $acct->disk_limit_mb ? $acct->disk_limit_mb.' MB' : 'Unlimited',
                'disk_pct' => $acct->diskUsagePercent() ?? 0,
                'bw_used' => $acct->bandwidth_used_mb !== null ? round($acct->bandwidth_used_mb, 1).' MB' : '-',
                'bw_limit' => $acct->bandwidth_limit_mb ? $acct->bandwidth_limit_mb.' MB' : 'Unlimited',
                'bw_pct' => $acct->bandwidthUsagePercent() ?? 0,
                'max_pct' => $acct->maxUsagePercent(),
                'state' => $state,
                'suspended' => $acct->suspended,
            ];
        })->sortByDesc('max_pct')->values()->all();
    }

    /**
     * Filtered + sorted rows untuk table.
     */
    #[Computed]
    public function usageList(): array
    {
        $rows = collect($this->allRows);

        if ($this->stateFilter) {
            $rows = $rows->where('state', $this->stateFilter);
        }

        return $rows->values()->all();
    }

    #[Computed]
    public function summary(): array
    {
        $all = collect($this->allRows);

        return [
            'total' => $all->count(),
            'ok' => $all->where('state', 'ok')->count(),
            'warning' => $all->where('state', 'warning')->count(),
            'critical' => $all->where('state', 'critical')->count(),
        ];
    }

    public function setStateFilter(string $state): void
    {
        $this->stateFilter = $this->stateFilter === $state ? '' : $state;
    }

    public function updatedServer(): void
    {
        unset($this->allRows, $this->usageList, $this->summary, $this->lastSyncedAt);
    }

    public function render()
    {
        return view('nawasara-whm::livewire.pages.usage.section.dashboard');
    }
}
