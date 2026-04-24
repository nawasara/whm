<?php

namespace Nawasara\Whm\Livewire\Server\Section;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Nawasara\Whm\Services\WhmClient;

class Overview extends Component
{
    #[Url(except: '')]
    public string $server = '';

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

    #[Computed]
    public function status(): array
    {
        if (! $this->client()->isConfigured()) {
            return [];
        }

        return $this->client()->getCachedServerStatus();
    }

    #[Computed]
    public function accountsCount(): array
    {
        $accounts = $this->client()->getCachedAccounts();
        $total = count($accounts);
        $suspended = collect($accounts)->where('suspended', 1)->count();

        return [
            'total' => $total,
            'active' => $total - $suspended,
            'suspended' => $suspended,
        ];
    }

    public function updatedServer(): void
    {
        unset($this->status, $this->accountsCount);
    }

    public function refresh(): void
    {
        $this->client()->flushCache();
        unset($this->status, $this->accountsCount);
        toaster_success('Server status di-refresh');
    }

    public function render()
    {
        return view('nawasara-whm::livewire.pages.server.section.overview');
    }
}
