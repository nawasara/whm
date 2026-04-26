<?php

namespace Nawasara\Whm\Livewire\MailLog\Section;

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nawasara\Ui\Livewire\Concerns\HasBrowserToast;
use Nawasara\Whm\Livewire\Concerns\HasServerRole;
use Nawasara\Whm\Services\EximClient;
use Nawasara\Whm\Services\WhmClient;

class Search extends Component
{
    use HasBrowserToast;
    use HasServerRole;

    protected function serverRole(): string
    {
        return 'mail';
    }

    #[Url(except: '')]
    public string $server = '';

    // Search form
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $sender = '';
    public string $recipient = '';
    public string $messageId = '';
    public string $status = '';
    public bool $hideNoise = true; // hide background SMTP/connection events
    public int $limit = 200;

    /** Last successful query result. */
    public array $results = [];
    public bool $hasSearched = false;
    public ?float $elapsedMs = null;
    public ?string $errorMessage = null;

    /** Trace modal state. */
    public ?string $traceId = null;
    public array $traceEvents = [];

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

        // Default range = today
        if (! $this->dateFrom) {
            $this->dateFrom = now()->format('Y-m-d');
        }
        if (! $this->dateTo) {
            $this->dateTo = now()->format('Y-m-d');
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

    public function search(): void
    {
        Gate::authorize('whm.maillog.view');

        $this->validate([
            'dateFrom' => 'nullable|date_format:Y-m-d',
            'dateTo' => 'nullable|date_format:Y-m-d',
            'sender' => 'nullable|string|max:200',
            'recipient' => 'nullable|string|max:200',
            'messageId' => 'nullable|string|max:64',
            'status' => 'nullable|in:received,delivered,bounced,deferred',
            'limit' => 'integer|min:10|max:5000',
        ]);

        if (! $this->isConfigured) {
            $this->errorMessage = 'SSH belum dikonfigurasi untuk server ini.';
            return;
        }

        $start = microtime(true);
        $this->errorMessage = null;

        try {
            $entries = $this->client()->searchLog([
                'date_from' => $this->dateFrom ?: null,
                'date_to' => $this->dateTo ?: null,
                'sender' => $this->sender ?: null,
                'recipient' => $this->recipient ?: null,
                'message_id' => $this->messageId ?: null,
                'status' => $this->status ?: null,
                'limit' => $this->limit,
            ]);

            // Hide noise = drop entries that have no message_id (background
            // events like "no host name found", "SMTP connection from",
            // dovecot auth fails, command echo). Those are useful for the
            // ops team but cluttered the table for the typical "did this
            // email get delivered?" use case.
            if ($this->hideNoise && ! $this->status) {
                $entries = array_values(array_filter($entries, fn ($e) => ! empty($e['message_id'])));
            }

            $this->results = $entries;
            $this->hasSearched = true;
            $this->elapsedMs = round((microtime(true) - $start) * 1000, 1);

            if (empty($this->results)) {
                $this->toastInfo('Tidak ada log entry yang cocok filter ini.');
            } else {
                $this->toastSuccess(count($this->results)." log entry ditemukan ({$this->elapsedMs} ms).");
            }
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
            $this->results = [];
            $this->toastError($e->getMessage());
        }
    }

    public function resetForm(): void
    {
        $this->reset(['sender', 'recipient', 'messageId', 'status', 'results', 'hasSearched', 'elapsedMs', 'errorMessage']);
        $this->dateFrom = now()->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
        $this->limit = 200;
        $this->hideNoise = true;
    }

    public function setQuickRange(string $range): void
    {
        $this->dateTo = now()->format('Y-m-d');
        $this->dateFrom = match ($range) {
            'today' => now()->format('Y-m-d'),
            '24h' => now()->subDay()->format('Y-m-d'),
            '7d' => now()->subDays(7)->format('Y-m-d'),
            '30d' => now()->subDays(30)->format('Y-m-d'),
            default => $this->dateFrom,
        };
    }

    public function openTrace(string $messageId): void
    {
        Gate::authorize('whm.maillog.view');

        try {
            $this->traceId = $messageId;
            $this->traceEvents = $this->client()->traceMessage($messageId);
            $this->dispatch('modal-open:whm-maillog-trace');
        } catch (\Throwable $e) {
            $this->toastError('Gagal trace: '.$e->getMessage());
        }
    }

    public function closeTrace(): void
    {
        $this->traceId = null;
        $this->traceEvents = [];
        $this->dispatch('modal-close:whm-maillog-trace');
    }

    public function render()
    {
        return view('nawasara-whm::livewire.pages.mail-log.section.search');
    }
}
