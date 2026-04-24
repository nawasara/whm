<?php

namespace Nawasara\Whm\Livewire\Package\Section;

use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Nawasara\Whm\Services\WhmClient;

class Table extends Component
{
    #[Url(except: '')]
    public string $server = '';

    public string $search = '';

    // Form state
    public string $formName = '';
    public string $formQuota = '5000';      // MB
    public string $formBwLimit = '50000';   // MB
    public string $formMaxPop = '10';
    public string $formMaxFtp = '5';
    public string $formMaxSql = '5';
    public string $formMaxSub = '10';
    public string $formMaxAddon = '5';
    public string $formMaxPark = '5';

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
    public function packages(): array
    {
        if (! $this->client()->isConfigured()) {
            return [];
        }

        $all = $this->client()->getCachedPackages();

        if ($this->search) {
            $needle = strtolower($this->search);
            $all = array_filter($all, fn ($p) => str_contains(strtolower($p['name'] ?? ''), $needle));
        }

        return array_values($all);
    }

    public function updatedServer(): void
    {
        unset($this->packages);
    }

    #[On('openCreatePackage')]
    public function openCreate(): void
    {
        Gate::authorize('whm.package.manage');

        $this->reset([
            'formName', 'formQuota', 'formBwLimit', 'formMaxPop',
            'formMaxFtp', 'formMaxSql', 'formMaxSub', 'formMaxAddon', 'formMaxPark',
        ]);
        $this->formQuota = '5000';
        $this->formBwLimit = '50000';
        $this->formMaxPop = '10';
        $this->formMaxFtp = '5';
        $this->formMaxSql = '5';
        $this->formMaxSub = '10';
        $this->formMaxAddon = '5';
        $this->formMaxPark = '5';

        $this->dispatch('modal-open:whm-package-form');
    }

    public function savePackage(): void
    {
        Gate::authorize('whm.package.manage');

        $this->validate([
            'formName' => 'required|string|max:45',
            'formQuota' => 'required',
            'formBwLimit' => 'required',
        ]);

        $result = $this->client()->createPackage([
            'name' => $this->formName,
            'quota' => $this->formQuota,
            'bwlimit' => $this->formBwLimit,
            'maxpop' => $this->formMaxPop,
            'maxftp' => $this->formMaxFtp,
            'maxsql' => $this->formMaxSql,
            'maxsub' => $this->formMaxSub,
            'maxaddon' => $this->formMaxAddon,
            'maxpark' => $this->formMaxPark,
        ]);

        if ($result['success']) {
            $this->client()->flushCache();
            unset($this->packages);
            toaster_success('Package berhasil dibuat');
            $this->dispatch('modal-close:whm-package-form');
        } else {
            toaster_error('Gagal membuat package: '.$result['message']);
        }
    }

    public function deletePackage(string $name): void
    {
        Gate::authorize('whm.package.manage');

        if ($this->client()->deletePackage($name)) {
            $this->client()->flushCache();
            unset($this->packages);
            toaster_success("Package {$name} berhasil dihapus");
        } else {
            toaster_error("Gagal menghapus package (mungkin masih dipakai akun)");
        }
    }

    public function render()
    {
        return view('nawasara-whm::livewire.pages.package.section.table');
    }
}
