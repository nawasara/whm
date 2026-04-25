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
     * Accounts dengan usage info, sorted by max usage descending.
     */
    #[Computed]
    public function usageList(): array
    {
        $accounts = WhmAccount::forInstance($this->server)->get();

        $rows = $accounts->map(function (WhmAccount $acct) {
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
        });

        if ($this->stateFilter) {
            $rows = $rows->where('state', $this->stateFilter);
        }

        return $rows->sortByDesc('max_pct')->values()->all();
    }

    #[Computed]
    public function summary(): array
    {
        $all = collect($this->usageList);

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
        unset($this->usageList, $this->summary, $this->lastSyncedAt);
    }

    public function render()
    {
        return view('nawasara-whm::livewire.pages.usage.section.dashboard');
    }
}
