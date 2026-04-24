<?php

namespace Nawasara\Whm\Livewire\Usage\Section;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Nawasara\Whm\Services\WhmClient;

class Dashboard extends Component
{
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
        $instances = $this->whm->instances();
        if (! $this->server && ! empty($instances)) {
            $this->server = $instances[0];
        }
    }

    protected function client(): WhmClient
    {
        return $this->server ? $this->whm->forInstance($this->server) : $this->whm;
    }

    #[Computed]
    public function servers(): array
    {
        return $this->whm->instances();
    }

    #[Computed]
    public function serverOptions(): array
    {
        return collect($this->servers)->mapWithKeys(fn ($s) => [$s => $s])->all();
    }

    /**
     * Returns accounts enriched with usage percentages + state.
     */
    #[Computed]
    public function usageList(): array
    {
        if (! $this->client()->isConfigured()) {
            return [];
        }

        $warnThreshold = config('nawasara-whm.usage_warning_threshold', 80);
        $critThreshold = config('nawasara-whm.usage_critical_threshold', 95);

        $accounts = collect($this->client()->getCachedAccounts())
            ->map(function ($acct) use ($warnThreshold, $critThreshold) {
                $diskUsed = $this->parseSize($acct['diskused'] ?? '0');
                $diskLimit = $this->parseSize($acct['disklimit'] ?? 'unlimited');
                $bwUsed = $this->parseSize($acct['totalbytes'] ?? $acct['bwused'] ?? '0');
                $bwLimit = $this->parseSize($acct['bwlimit'] ?? 'unlimited');

                $diskPct = $diskLimit > 0 ? round(($diskUsed / $diskLimit) * 100, 1) : 0;
                $bwPct = $bwLimit > 0 ? round(($bwUsed / $bwLimit) * 100, 1) : 0;

                $maxPct = max($diskPct, $bwPct);

                $state = 'ok';
                if ($maxPct >= $critThreshold) $state = 'critical';
                elseif ($maxPct >= $warnThreshold) $state = 'warning';

                return [
                    'user' => $acct['user'] ?? '',
                    'domain' => $acct['domain'] ?? '',
                    'plan' => $acct['plan'] ?? '',
                    'disk_used' => $acct['diskused'] ?? '-',
                    'disk_limit' => $acct['disklimit'] ?? 'unlimited',
                    'disk_pct' => $diskPct,
                    'bw_used' => $acct['totalbytes'] ?? $acct['bwused'] ?? '-',
                    'bw_limit' => $acct['bwlimit'] ?? 'unlimited',
                    'bw_pct' => $bwPct,
                    'max_pct' => $maxPct,
                    'state' => $state,
                    'suspended' => ($acct['suspended'] ?? 0) == 1,
                ];
            });

        // Filter by state
        if ($this->stateFilter) {
            $accounts = $accounts->where('state', $this->stateFilter);
        }

        return $accounts->sortByDesc('max_pct')->values()->all();
    }

    #[Computed]
    public function summary(): array
    {
        if (! $this->client()->isConfigured()) {
            return ['total' => 0, 'ok' => 0, 'warning' => 0, 'critical' => 0];
        }

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
        unset($this->usageList, $this->summary);
    }

    /**
     * Parse size strings like "1500M", "2G", "unlimited" to MB.
     */
    protected function parseSize($input): float
    {
        if ($input === 'unlimited' || $input === null || $input === '') {
            return 0;
        }

        if (is_numeric($input)) {
            return (float) $input;
        }

        $input = strtoupper((string) $input);

        if (preg_match('/^([\d.]+)\s*(K|M|G|T)?B?$/', $input, $m)) {
            $num = (float) $m[1];
            $unit = $m[2] ?? 'M';

            return match ($unit) {
                'K' => $num / 1024,
                'M' => $num,
                'G' => $num * 1024,
                'T' => $num * 1024 * 1024,
                default => $num,
            };
        }

        return 0;
    }

    public function render()
    {
        return view('nawasara-whm::livewire.pages.usage.section.dashboard');
    }
}
