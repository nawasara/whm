<?php

namespace Nawasara\Whm\Livewire\EmailStats\Section;

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nawasara\Ui\Livewire\Concerns\HasBrowserToast;
use Nawasara\Whm\Livewire\Concerns\HasServerRole;
use Nawasara\Whm\Services\EmailStatsAggregator;
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

    protected WhmClient $whm;
    protected EmailStatsAggregator $stats;

    public function boot(WhmClient $whm, EmailStatsAggregator $stats)
    {
        $this->whm = $whm;
        $this->stats = $stats;
    }

    public function mount(): void
    {
        Gate::authorize('whm.emailstats.view');

        if (! $this->server) {
            $this->server = $this->defaultInstance($this->whm) ?? '';
        }
    }

    protected function aggregator(): EmailStatsAggregator
    {
        return $this->server ? $this->stats->forInstance($this->server) : $this->stats;
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
            return ['received' => 0, 'delivered' => 0, 'bounced' => 0, 'deferred' => 0, 'spam' => 0];
        }

        try {
            return $this->aggregator()->getCounts(now(), now());
        } catch (\Throwable $e) {
            return ['received' => 0, 'delivered' => 0, 'bounced' => 0, 'deferred' => 0, 'spam' => 0];
        }
    }

    #[Computed]
    public function queueSize(): int
    {
        if (! $this->isConfigured) {
            return 0;
        }
        try {
            return $this->aggregator()->getQueueSize();
        } catch (\Throwable $e) {
            return 0;
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
            $rowMax = max($row['received'], $row['delivered'], $row['bounced'], $row['deferred']);
            if ($rowMax > $max) {
                $max = $rowMax;
            }
        }
        return max(1, $max);
    }

    #[Computed]
    public function topSenders(): array
    {
        if (! $this->isConfigured) {
            return [];
        }
        try {
            return $this->aggregator()->getTopSenders(10);
        } catch (\Throwable $e) {
            return [];
        }
    }

    #[Computed]
    public function topDomains(): array
    {
        if (! $this->isConfigured) {
            return [];
        }
        try {
            return $this->aggregator()->getTopRecipientDomains(10);
        } catch (\Throwable $e) {
            return [];
        }
    }

    #[Computed]
    public function hourly(): array
    {
        if (! $this->isConfigured) {
            return array_fill(0, 24, ['hour' => 0, 'count' => 0]);
        }
        try {
            return $this->aggregator()->getHourlyVolumeToday();
        } catch (\Throwable $e) {
            return array_fill(0, 24, ['hour' => 0, 'count' => 0]);
        }
    }

    #[Computed]
    public function hourlyMax(): int
    {
        $max = 0;
        foreach ($this->hourly as $h) {
            if ($h['count'] > $max) {
                $max = $h['count'];
            }
        }
        return max(1, $max);
    }

    public function setTrendDays(int $days): void
    {
        $this->trendDays = in_array($days, [3, 7, 14, 30], true) ? $days : 7;
    }

    public function refresh(): void
    {
        // Bust all computed memoization so wire:click "Refresh" re-fetches.
        foreach (['todayCounts', 'queueSize', 'trend', 'trendMax', 'topSenders', 'topDomains', 'hourly', 'hourlyMax'] as $prop) {
            unset($this->{$prop});
        }
        $this->toastSuccess('Stats di-refresh.');
    }

    public function render()
    {
        return view('nawasara-whm::livewire.pages.email-stats.section.overview');
    }
}
