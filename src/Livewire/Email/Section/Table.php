<?php

namespace Nawasara\Whm\Livewire\Email\Section;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nawasara\Core\Models\WebmailSession;
use Nawasara\Ui\Livewire\Concerns\HasBrowserToast;
use Nawasara\Ui\Livewire\Concerns\HasExport;
use Nawasara\Whm\Exceptions\WebmailSessionException;
use Nawasara\Whm\Livewire\Concerns\HasServerRole;
use Nawasara\Whm\Models\WhmEmailAccount;
use Nawasara\Whm\Repositories\WhmEmailAccountRepository;
use Nawasara\Whm\Services\WebmailSessionService;
use Nawasara\Whm\Services\WhmClient;

class Table extends Component
{
    use HasBrowserToast;
    use HasExport;
    use HasServerRole;
    use WithPagination;

    protected function serverRole(): string
    {
        return 'mail';
    }

    #[Url(except: '')]
    public string $server = '';

    public string $search = '';

    /**
     * Multi-select status filter (filter-panel array semantics).
     * Empty array == no filter; both selected == no-op (any row matches).
     *
     * @var array<int, string>
     */
    public array $statusFilter = [];

    public int $perPage = 25;

    // Create modal
    public string $formLocalPart = '';
    public string $formDomain = '';
    public string $formPassword = '';
    public int $formQuota = 250;

    // Password change modal
    public string $pwEmail = '';
    public string $pwNewPassword = '';

    // Quota change modal
    public string $quotaEmail = '';
    public int $quotaNew = 250;

    // Detail modal
    public ?int $detailId = null;

    // Bulk selection (array of email account IDs)
    public array $selected = [];
    public bool $selectAll = false;

    // Bulk quota modal
    public int $bulkQuotaNew = 250;

    // Bulk password modal (single password applied to all selected)
    public string $bulkNewPassword = '';

    // Launch-as (admin impersonation) modal state. Sengaja terpisah dari
    // password/quota modal supaya state tidak bocor antar flow — admin yang
    // baru saja edit password bisa langsung klik "Buka sebagai" tanpa stale
    // form data.
    public string $launchAsEmail = '';
    public string $launchAsReason = '';

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

    protected function repo(): WhmEmailAccountRepository
    {
        return new WhmEmailAccountRepository($this->server ?: null);
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
    public function cpanelUser(): ?string
    {
        return $this->client()->defaultCpanelUser();
    }

    #[Computed]
    public function domains(): array
    {
        return WhmEmailAccount::forInstance($this->server)
            ->select('domain')
            ->distinct()
            ->orderBy('domain')
            ->pluck('domain')
            ->all();
    }

    #[Computed]
    public function lastSyncedAt(): ?string
    {
        $when = $this->repo()->lastSyncedAt();
        return $when ? $when->diffForHumans() : null;
    }

    #[Computed]
    public function pendingCount(): int
    {
        return WhmEmailAccount::forInstance($this->server)
            ->pendingSync()
            ->count();
    }

    #[Computed]
    public function accounts()
    {
        return $this->repo()->list([
            'search' => $this->search ?: null,
            // Empty array passes through; polymorphic scope is no-op on empty.
            'status' => $this->statusFilter,
        ], $this->perPage);
    }

    public function updatedSearch(): void { $this->resetPage(); $this->resetSelection(); }
    public function updatedStatusFilter(): void { $this->resetPage(); $this->resetSelection(); }
    public function updatedServer(): void { $this->resetPage(); $this->resetSelection(); }

    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value ? $this->accounts->pluck('id')->map(fn ($id) => (string) $id)->all() : [];
    }

    public function resetSelection(): void
    {
        $this->selected = [];
        $this->selectAll = false;
    }

    // ─── Sync ───────────────────────────────────────────

    public function refreshList(): void
    {
        Gate::authorize('whm.email.view');

        $this->repo()->syncNow();
        $this->toastSuccess('Sync dispatched. Data akan refresh dalam beberapa detik.');
    }

    // ─── Create ─────────────────────────────────────────

    #[On('openCreateEmail')]
    public function openCreate(): void
    {
        Gate::authorize('whm.email.create');
        $this->reset(['formLocalPart', 'formPassword']);
        $this->formQuota = 250;

        $this->formDomain = $this->domains[0] ?? '';

        $this->dispatch('modal-open:whm-email-form');
    }

    public function generatePassword(): void
    {
        $this->formPassword = Str::password(16, true, true, true, false);
    }

    public function save(): void
    {
        Gate::authorize('whm.email.create');

        $this->validate([
            'formLocalPart' => 'required|alpha_dash|max:64',
            'formDomain' => 'required|string',
            'formPassword' => 'required|min:8',
            'formQuota' => 'required|integer|min:0',
        ]);

        $email = $this->formLocalPart.'@'.$this->formDomain;

        try {
            $this->repo()->create([
                'email' => $email,
                'password' => $this->formPassword,
                'quota_mb' => $this->formQuota,
            ]);

            $this->toastSuccess("Email {$email} sedang dibuat. Cek status di Sync Jobs.");
            $this->dispatch('modal-close:whm-email-form');
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    // ─── Change Password ────────────────────────────────

    public function openChangePassword(string $email): void
    {
        Gate::authorize('whm.email.manage');
        $this->pwEmail = $email;
        $this->pwNewPassword = '';
        $this->dispatch('modal-open:whm-email-password');
    }

    public function generatePasswordReset(): void
    {
        $this->pwNewPassword = Str::password(16, true, true, true, false);
    }

    public function doChangePassword(): void
    {
        Gate::authorize('whm.email.manage');

        $this->validate(['pwNewPassword' => 'required|min:8']);

        try {
            $this->repo()->update($this->pwEmail, ['password' => $this->pwNewPassword]);
            $this->toastSuccess("Password {$this->pwEmail} sedang di-update.");
            $this->dispatch('modal-close:whm-email-password');
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    // ─── Change Quota ───────────────────────────────────

    public function openChangeQuota(string $email, ?int $currentQuota = null): void
    {
        Gate::authorize('whm.email.manage');
        $this->quotaEmail = $email;
        $this->quotaNew = $currentQuota ?? 0;
        $this->dispatch('modal-open:whm-email-quota');
    }

    public function doChangeQuota(): void
    {
        Gate::authorize('whm.email.manage');

        $this->validate(['quotaNew' => 'required|integer|min:0']);

        try {
            $this->repo()->update($this->quotaEmail, ['quota_mb' => $this->quotaNew]);
            $this->toastSuccess("Quota {$this->quotaEmail} sedang di-update.");
            $this->dispatch('modal-close:whm-email-quota');
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    // ─── Suspend / Unsuspend ────────────────────────────

    public function suspend(string $email): void
    {
        Gate::authorize('whm.email.manage');

        try {
            $this->repo()->update($email, ['suspend_login' => true, 'suspend_incoming' => true]);
            $this->toastSuccess("Suspend dispatched untuk {$email}.");
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function unsuspend(string $email): void
    {
        Gate::authorize('whm.email.manage');

        try {
            $this->repo()->unsuspend($email);
            $this->toastSuccess("Unsuspend dispatched untuk {$email}.");
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    // ─── Delete ─────────────────────────────────────────

    public function delete(string $email): void
    {
        Gate::authorize('whm.email.manage');

        try {
            $this->repo()->delete($email);
            $this->toastSuccess("Delete dispatched untuk {$email}.");
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    // ─── Launch as (admin impersonation) ────────────────

    /**
     * Buka modal konfirmasi sebelum admin masuk ke webmail user.
     * Reason wajib diisi supaya audit trail actionable — atasan harus tahu
     * kenapa email user X diakses oleh admin Y.
     *
     * Permission check di sini PLUS di submit handler — defense in depth,
     * jangan cuma di submit (kalau user manipulasi DOM, modal masih bisa
     * kebuka tapi submit akan ditolak).
     */
    public function openLaunchAs(string $email): void
    {
        Gate::authorize('webmail.session.launch_as');

        $this->launchAsEmail = $email;
        $this->launchAsReason = '';
        $this->resetErrorBag('launchAsReason');

        $this->dispatch('modal-open:whm-email-launch-as');
    }

    /**
     * Forge session URL untuk mailbox target + redirect admin browser ke
     * Roundcube. Audit log inserted dulu sebelum redirect supaya kalau
     * redirect gagal di tengah jalan, jejaknya tetap ada.
     *
     * Kenapa Livewire yang return redirect (bukan controller terpisah):
     *   - Action ini single-step, tidak ada GET vs POST distinction
     *   - State (email + reason) sudah ada di Livewire component
     *   - Mengikuti pattern Livewire 3: `return redirect()->away(...)` di
     *     action method legal dan di-handle properly oleh Livewire
     */
    public function confirmLaunchAs(WebmailSessionService $service)
    {
        Gate::authorize('webmail.session.launch_as');

        $this->validate([
            'launchAsEmail' => 'required|email',
            'launchAsReason' => 'required|string|min:10|max:500',
        ], [], [
            'launchAsReason' => 'alasan akses',
        ]);

        $email = $this->launchAsEmail;
        $reason = trim($this->launchAsReason);

        // Resolve target user (kalau email ke-link ke user Nawasara). Best-effort
        // — kalau tidak ke-link, tetap log dengan target_user_id null. Audit
        // page bisa filter "akses email yang tidak terhubung user" kalau perlu.
        $targetUserId = $this->resolveTargetUserId($email);

        $instance = $this->server ?: null;

        try {
            $result = $service->createWebmailUrl($email, $instance);
        } catch (WebmailSessionException $e) {
            $this->logImpersonationAttempt(
                email: $email,
                reason: $reason,
                targetUserId: $targetUserId,
                status: WebmailSession::STATUS_FAILED,
                error: $e->getMessage(),
            );

            Log::warning('[webmail] admin launch-as failed', [
                'admin_id' => auth()->id(),
                'target_email' => $email,
                'message' => $e->getMessage(),
            ]);

            $this->dispatch('modal-close:whm-email-launch-as');
            $this->toastError('Gagal forge session: '.$e->getMessage());
            return null;
        }

        $this->logImpersonationAttempt(
            email: $email,
            reason: $reason,
            targetUserId: $targetUserId,
            status: WebmailSession::STATUS_ISSUED,
            error: null,
        );

        // Redirect tab BARU akan di-handle browser-side via JS (target=_blank).
        // Livewire `redirect()->away()` akan navigate current tab. Untuk admin
        // yang lagi mid-task tidak ideal, jadi kita dispatch event ke JS yang
        // window.open() di tab baru. Modal close sekaligus.
        $this->dispatch('modal-close:whm-email-launch-as');
        $this->dispatch('webmail-launch-window', url: $result['url'], expiresIn: $result['expires_in']);

        return null;
    }

    /**
     * Lookup user yang punya UserEmailLink ke mailbox ini. Best-effort:
     * - Manual link menang atas SSO link kalau dua-duanya ada
     * - Kalau >1 user link ke email yang sama (rare, biasanya admin set
     *   shared mailbox), pakai yang paling baru di-update
     *
     * Return null kalau email tidak ke-link ke siapapun — itu OK, audit log
     * tetap bisa di-insert dengan user_id null (admin akses email "yatim").
     */
    protected function resolveTargetUserId(string $email): ?int
    {
        // Lazy import — kalau nawasara-core belum loaded, jangan crash. Tapi
        // realistically nawasara-whm depend ke nawasara-core, jadi kondisi ini
        // hampir tidak mungkin. Defensive aja.
        $linkClass = \Nawasara\Core\Models\UserEmailLink::class;
        if (! class_exists($linkClass)) {
            return null;
        }

        $link = $linkClass::query()
            ->where('email_account', $email)
            ->orderByRaw("CASE WHEN source = 'manual' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->first();

        return $link?->user_id;
    }

    /**
     * Insert audit row untuk impersonation attempt. Kalau insert sendiri
     * gagal (DB issue), log warning tapi JANGAN throw — admin tetap perlu
     * dapet redirect / error feedback untuk action utamanya.
     */
    protected function logImpersonationAttempt(
        string $email,
        string $reason,
        ?int $targetUserId,
        string $status,
        ?string $error,
    ): void {
        try {
            WebmailSession::create([
                'user_id' => $targetUserId,                    // target (boleh null)
                'acted_by_user_id' => auth()->id(),            // admin yang launch
                'email_account' => $email,
                'match_strategy' => null,                      // N/A untuk impersonation
                'launch_kind' => WebmailSession::KIND_IMPERSONATION,
                'reason' => $reason,
                'ip' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
                'status' => $status,
                'error' => $error,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[webmail] impersonation audit log failed: '.$e->getMessage(), [
                'admin_id' => auth()->id(),
                'target_email' => $email,
            ]);
        }
    }

    // ─── Detail ─────────────────────────────────────────

    public function openDetail(int $id): void
    {
        $this->detailId = $id;
        $this->dispatch('modal-open:whm-email-detail');
    }

    #[Computed]
    public function detail(): ?WhmEmailAccount
    {
        return $this->detailId ? WhmEmailAccount::find($this->detailId) : null;
    }

    // ─── Bulk Operations ────────────────────────────────

    /** Resolve selected IDs to email addresses (current instance only). */
    protected function selectedEmails(): array
    {
        if (empty($this->selected)) {
            return [];
        }

        return WhmEmailAccount::forInstance($this->server)
            ->whereIn('id', $this->selected)
            ->pluck('email')
            ->all();
    }

    public function bulkSuspend(): void
    {
        Gate::authorize('whm.email.manage');

        $emails = $this->selectedEmails();
        if (empty($emails)) {
            $this->toastError('Tidak ada email yang dipilih.');
            return;
        }

        $count = 0;
        foreach ($emails as $email) {
            try {
                $this->repo()->update($email, ['suspend_login' => true, 'suspend_incoming' => true]);
                $count++;
            } catch (\Throwable $e) {
                // Skip failures; per-record sync_error will reflect status
            }
        }

        $this->toastSuccess("Suspend dispatched untuk {$count} email.");
        $this->resetSelection();
    }

    public function bulkUnsuspend(): void
    {
        Gate::authorize('whm.email.manage');

        $emails = $this->selectedEmails();
        if (empty($emails)) {
            $this->toastError('Tidak ada email yang dipilih.');
            return;
        }

        $count = 0;
        foreach ($emails as $email) {
            try {
                $this->repo()->unsuspend($email);
                $count++;
            } catch (\Throwable $e) {
                // Skip failures
            }
        }

        $this->toastSuccess("Unsuspend dispatched untuk {$count} email.");
        $this->resetSelection();
    }

    public function bulkDelete(): void
    {
        Gate::authorize('whm.email.manage');

        $emails = $this->selectedEmails();
        if (empty($emails)) {
            $this->toastError('Tidak ada email yang dipilih.');
            return;
        }

        $count = 0;
        foreach ($emails as $email) {
            try {
                $this->repo()->delete($email);
                $count++;
            } catch (\Throwable $e) {
                // Skip failures
            }
        }

        $this->toastSuccess("Delete dispatched untuk {$count} email.");
        $this->resetSelection();
    }

    public function openBulkQuota(): void
    {
        Gate::authorize('whm.email.manage');

        if (empty($this->selected)) {
            $this->toastError('Tidak ada email yang dipilih.');
            return;
        }

        $this->bulkQuotaNew = 250;
        $this->dispatch('modal-open:whm-email-bulk-quota');
    }

    public function doBulkQuota(): void
    {
        Gate::authorize('whm.email.manage');

        $this->validate(['bulkQuotaNew' => 'required|integer|min:0']);

        $emails = $this->selectedEmails();
        $count = 0;
        foreach ($emails as $email) {
            try {
                $this->repo()->update($email, ['quota_mb' => $this->bulkQuotaNew]);
                $count++;
            } catch (\Throwable $e) {
                // Skip failures
            }
        }

        $this->toastSuccess("Quota update dispatched untuk {$count} email.");
        $this->dispatch('modal-close:whm-email-bulk-quota');
        $this->resetSelection();
    }

    /**
     * Export filename base — timestamp + extension appended by HasExport.
     */
    protected function exportFilename(): string
    {
        $slug = $this->server ?: 'all';
        return 'whm-email-accounts-'.preg_replace('/[^a-z0-9-]+/i', '-', $slug);
    }

    /**
     * Export FULL email account list for the active server (no filter).
     * Dual suspended flags exposed verbatim because operators distinguish
     * "can't login" from "can't receive" in incident triage.
     */
    protected function exportData(): iterable
    {
        return WhmEmailAccount::forInstance($this->server)
            ->orderBy('domain')
            ->orderBy('local_part')
            ->get()
            ->map(fn (WhmEmailAccount $a) => [
                'Server' => $a->instance,
                'cPanel User' => $a->cpanel_user,
                'Email' => $a->email,
                'Local Part' => $a->local_part,
                'Domain' => $a->domain,
                'Quota MB' => $a->quota_mb,
                'Disk Used MB' => $a->disk_used_mb,
                'Suspended Login' => $a->suspended_login ? 'Yes' : 'No',
                'Suspended Incoming' => $a->suspended_incoming ? 'Yes' : 'No',
                'Last Synced' => optional($a->last_synced_at)->format('Y-m-d H:i'),
            ]);
    }

    public function render()
    {
        return view('nawasara-whm::livewire.pages.email.section.table');
    }
}
