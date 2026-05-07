<?php

namespace Nawasara\Whm\Livewire\MailQueue\Section;

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nawasara\Ui\Livewire\Concerns\HasBrowserToast;
use Nawasara\Whm\Livewire\Concerns\HasServerRole;
use Nawasara\Whm\Services\EximClient;
use Nawasara\Whm\Services\WhmClient;

class Table extends Component
{
    use HasBrowserToast;
    use HasServerRole;

    protected function serverRole(): string
    {
        return 'mail';
    }

    #[Url(except: '')]
    public string $server = '';

    public string $search = '';

    /**
     * Multi-select status filter (filter-panel array semantics).
     * Empty array == no filter. queued/deferred/frozen are mutually
     * exclusive Exim states so multi-select == "match any".
     *
     * @var array<int, string>
     */
    public array $statusFilter = [];

    /**
     * Single-select age threshold ('1h' / '24h' / '7d' / '').
     * Stays scalar because the underlying filter is "older than X" — a
     * monotonic threshold, not a multi-value dimension.
     */
    public string $ageFilter = '';

    public int $perPage = 50;
    public int $page = 1;

    /** Bulk selection (queue ids — strings). */
    public array $selected = [];
    public bool $selectAll = false;

    /** Detail modal state. */
    public ?string $detailId = null;
    public ?string $detailHeaders = null;
    public ?string $detailBody = null;
    public ?string $detailLog = null;
    public string $detailTab = 'log'; // 'log' | 'headers' | 'body'

    public bool $loaded = false;

    protected WhmClient $whm;
    protected EximClient $exim;

    public function boot(WhmClient $whm, EximClient $exim)
    {
        $this->whm = $whm;
        $this->exim = $exim;
    }

    public function mount(): void
    {
        if (! $this->server) {
            $this->server = $this->defaultInstance($this->whm) ?? '';
        }
    }

    protected function client(): EximClient
    {
        return $this->server ? $this->exim->forInstance($this->server) : $this->exim;
    }

    #[Computed]
    public function servers(): array
    {
        return $this->rolledInstances($this->whm);
    }

    #[Computed]
    public function isConfigured(): bool
    {
        return $this->server && $this->client()->isConfigured();
    }

    /**
     * Full queue (server-side fetch). Cached per render via Livewire's
     * computed property memoization. For very large queues consider
     * paginating server-side via `exim -bp | head -n N`, but for typical
     * Kominfo loads (<5k entries) this is fine.
     */
    #[Computed]
    public function allItems(): array
    {
        if (! $this->isConfigured) {
            return [];
        }

        try {
            return $this->client()->getQueue();
        } catch (\Throwable $e) {
            $this->toastError('Gagal ambil queue: '.$e->getMessage());
            return [];
        }
    }

    /**
     * Filtered queue (search, status, age).
     */
    #[Computed]
    public function items(): array
    {
        $items = $this->allItems;

        $search = trim($this->search);
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $items = array_values(array_filter($items, function ($item) use ($needle) {
                if (str_contains(mb_strtolower($item['id']), $needle)) {
                    return true;
                }
                if ($item['sender'] && str_contains(mb_strtolower($item['sender']), $needle)) {
                    return true;
                }
                foreach ($item['recipients'] as $rcpt) {
                    if (str_contains(mb_strtolower($rcpt), $needle)) {
                        return true;
                    }
                }
                return false;
            }));
        }

        if (! empty($this->statusFilter)) {
            $items = array_values(array_filter(
                $items,
                fn ($i) => in_array($i['status'], $this->statusFilter, true),
            ));
        }

        if ($this->ageFilter !== '') {
            $threshold = match ($this->ageFilter) {
                '1h' => 3600,
                '24h' => 86400,
                '7d' => 604800,
                default => 0,
            };
            if ($threshold > 0) {
                $items = array_values(array_filter($items, fn ($i) => $i['age_seconds'] >= $threshold));
            }
        }

        return $items;
    }

    /**
     * Slice for current page.
     */
    #[Computed]
    public function pagedItems(): array
    {
        $offset = max(0, ($this->page - 1) * $this->perPage);
        return array_slice($this->items, $offset, $this->perPage);
    }

    #[Computed]
    public function totalPages(): int
    {
        return max(1, (int) ceil(count($this->items) / max(1, $this->perPage)));
    }

    #[Computed]
    public function statusCounts(): array
    {
        $counts = ['queued' => 0, 'deferred' => 0, 'frozen' => 0];
        foreach ($this->allItems as $item) {
            $counts[$item['status']] = ($counts[$item['status']] ?? 0) + 1;
        }
        return $counts;
    }

    public function updatedSearch(): void { $this->page = 1; $this->resetSelection(); }
    public function updatedStatusFilter(): void { $this->page = 1; $this->resetSelection(); }
    public function updatedAgeFilter(): void { $this->page = 1; $this->resetSelection(); }
    public function updatedServer(): void { $this->page = 1; $this->resetSelection(); }

    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value
            ? array_map(fn ($i) => $i['id'], $this->pagedItems)
            : [];
    }

    public function resetSelection(): void
    {
        $this->selected = [];
        $this->selectAll = false;
    }

    public function refresh(): void
    {
        // Force re-fetch by clearing the computed memoization.
        unset($this->allItems);
        $this->resetSelection();
    }

    public function loadQueue(): void
    {
        $this->loaded = true;
    }

    // ─── Detail ─────────────────────────────────────────

    public function openDetail(string $messageId): void
    {
        Gate::authorize('whm.mailqueue.view');

        try {
            $this->detailId = $messageId;
            $this->detailTab = 'log';
            $this->detailLog = $this->client()->messageDeliveryLog($messageId);
            $this->detailHeaders = $this->client()->messageHeaders($messageId);
            $this->detailBody = $this->client()->messageBody($messageId, 100);
            $this->dispatch('modal-open:whm-mailqueue-detail');
        } catch (\Throwable $e) {
            $this->toastError('Gagal load detail: '.$e->getMessage());
        }
    }

    public function setDetailTab(string $tab): void
    {
        $this->detailTab = in_array($tab, ['log', 'headers', 'body'], true) ? $tab : 'log';
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
        $this->detailHeaders = null;
        $this->detailBody = null;
        $this->detailLog = null;
        $this->dispatch('modal-close:whm-mailqueue-detail');
    }

    // ─── Single-row actions ─────────────────────────────

    public function deleteOne(string $messageId): void
    {
        Gate::authorize('whm.mailqueue.manage');

        try {
            if ($this->client()->deleteFromQueue($messageId)) {
                $this->toastSuccess("Message {$messageId} dihapus dari queue.");
                $this->refresh();
            } else {
                $this->toastError("Gagal hapus {$messageId} — periksa Exim log.");
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function freezeOne(string $messageId): void
    {
        Gate::authorize('whm.mailqueue.manage');

        try {
            if ($this->client()->freeze($messageId)) {
                $this->toastSuccess("Message {$messageId} di-freeze.");
                $this->refresh();
            } else {
                $this->toastError("Gagal freeze {$messageId}.");
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function thawOne(string $messageId): void
    {
        Gate::authorize('whm.mailqueue.manage');

        try {
            if ($this->client()->thaw($messageId)) {
                $this->toastSuccess("Message {$messageId} di-thaw.");
                $this->refresh();
            } else {
                $this->toastError("Gagal thaw {$messageId}.");
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function forceOne(string $messageId): void
    {
        Gate::authorize('whm.mailqueue.manage');

        try {
            if ($this->client()->forceDelivery($messageId)) {
                $this->toastSuccess("Force delivery dispatched untuk {$messageId}. Cek detail untuk lihat hasil attempt-nya.");
                $this->refresh();
            } else {
                $this->toastError("Gagal force delivery {$messageId}.");
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function bounceOne(string $messageId): void
    {
        Gate::authorize('whm.mailqueue.manage');

        try {
            if ($this->client()->bounce($messageId)) {
                $this->toastSuccess("Message {$messageId} di-bounce — sender mendapat notice delivery failed.");
                $this->refresh();
            } else {
                $this->toastError("Gagal bounce {$messageId}.");
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    // ─── Bulk actions ───────────────────────────────────

    public function bulkDelete(): void
    {
        Gate::authorize('whm.mailqueue.manage');

        if (empty($this->selected)) {
            $this->toastError('Tidak ada message yang dipilih.');
            return;
        }

        try {
            $count = $this->client()->deleteManyFromQueue($this->selected);
            $this->toastSuccess("{$count} message dihapus dari queue.");
            $this->refresh();
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function bulkFreeze(): void
    {
        Gate::authorize('whm.mailqueue.manage');

        if (empty($this->selected)) {
            $this->toastError('Tidak ada message yang dipilih.');
            return;
        }

        $count = 0;
        foreach ($this->selected as $id) {
            try {
                if ($this->client()->freeze($id)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                // Skip; continue with rest
            }
        }

        $this->toastSuccess("{$count} message di-freeze.");
        $this->refresh();
    }

    public function bulkForce(): void
    {
        Gate::authorize('whm.mailqueue.manage');

        if (empty($this->selected)) {
            $this->toastError('Tidak ada message yang dipilih.');
            return;
        }

        $count = 0;
        foreach ($this->selected as $id) {
            try {
                if ($this->client()->forceDelivery($id)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                // Skip
            }
        }

        $this->toastSuccess("Force delivery dispatched untuk {$count} message.");
        $this->refresh();
    }

    public function flushAll(): void
    {
        Gate::authorize('whm.mailqueue.manage');

        try {
            if ($this->client()->flushQueue()) {
                $this->toastSuccess('Queue flush dispatched. Cek status beberapa saat lagi.');
                $this->refresh();
            } else {
                $this->toastError('Gagal flush queue.');
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function nextPage(): void
    {
        if ($this->page < $this->totalPages) {
            $this->page++;
            $this->resetSelection();
        }
    }

    public function prevPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
            $this->resetSelection();
        }
    }

    public function render()
    {
        return view('nawasara-whm::livewire.pages.mail-queue.section.table');
    }
}
