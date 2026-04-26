<?php

namespace Nawasara\Whm\Livewire\Spam\Section;

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nawasara\Ui\Livewire\Concerns\HasBrowserToast;
use Nawasara\Whm\Livewire\Concerns\HasServerRole;
use Nawasara\Whm\Services\MailSecurityAggregator;
use Nawasara\Whm\Services\WhmClient;

class Overview extends Component
{
    use HasBrowserToast;
    use HasServerRole;

    protected function serverRole(): string
    {
        return 'mail';
    }

    #[Url(except: '')]
    public string $server = '';

    public int $trendDays = 7;
    public int $recentLimit = 50;
    public bool $loaded = false;

    protected WhmClient $whm;
    protected MailSecurityAggregator $sec;

    public function boot(WhmClient $whm, MailSecurityAggregator $sec)
    {
        $this->whm = $whm;
        $this->sec = $sec;
    }

    public function mount(): void
    {
        Gate::authorize('whm.spam.view');

        if (! $this->server) {
            $this->server = $this->defaultInstance($this->whm) ?? '';
        }
    }

    protected function aggregator(): MailSecurityAggregator
    {
        return $this->server ? $this->sec->forInstance($this->server) : $this->sec;
    }

    #[Computed]
    public function servers(): array
    {
        return $this->rolledInstances($this->whm);
    }

    #[Computed]
    public function isConfigured(): bool
    {
        return $this->server && $this->aggregator()->isConfigured();
    }

    #[Computed]
    public function todayCounts(): array
    {
        if (! $this->isConfigured) {
            return ['total' => 0, 'auth_fail' => 0, 'rbl' => 0, 'unknown_user' => 0, 'spam' => 0, 'other' => 0];
        }
        try {
            return $this->aggregator()->getRejectCounts(now(), now());
        } catch (\Throwable $e) {
            return ['total' => 0, 'auth_fail' => 0, 'rbl' => 0, 'unknown_user' => 0, 'spam' => 0, 'other' => 0];
        }
    }

    #[Computed]
    public function trend(): array
    {
        if (! $this->isConfigured) {
            return [];
        }
        try {
            return $this->aggregator()->getDailyTrend($this->trendDays);
        } catch (\Throwable $e) {
            return [];
        }
    }

    #[Computed]
    public function trendMax(): int
    {
        $max = 0;
        foreach ($this->trend as $row) {
            if ($row['total'] > $max) {
                $max = $row['total'];
            }
        }
        return max(1, $max);
    }

    #[Computed]
    public function topIps(): array
    {
        if (! $this->isConfigured) {
            return [];
        }
        try {
            return $this->aggregator()->getTopBlockedIps(15);
        } catch (\Throwable $e) {
            return [];
        }
    }

    #[Computed]
    public function topTargets(): array
    {
        if (! $this->isConfigured) {
            return [];
        }
        try {
            return $this->aggregator()->getTopTargetedAccounts(15);
        } catch (\Throwable $e) {
            return [];
        }
    }

    #[Computed]
    public function recent(): array
    {
        if (! $this->isConfigured) {
            return [];
        }
        try {
            return $this->aggregator()->getRecentRejects($this->recentLimit);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function setTrendDays(int $days): void
    {
        $this->trendDays = in_array($days, [3, 7, 14, 30], true) ? $days : 7;
    }

    public function loadStats(): void
    {
        $this->loaded = true;
    }

    public function refresh(): void
    {
        foreach (['todayCounts', 'trend', 'trendMax', 'topIps', 'topTargets', 'recent'] as $prop) {
            unset($this->{$prop});
        }
        $this->toastSuccess('Stats di-refresh.');
    }

    public function render()
    {
        return view('nawasara-whm::livewire.pages.spam.section.overview');
    }
}
