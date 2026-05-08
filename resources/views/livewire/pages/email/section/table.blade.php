<div>
    @if (! count($this->servers))
        <div class="text-center py-12 border-2 border-dashed border-gray-200 dark:border-neutral-700 rounded-xl">
            <x-lucide-mail class="size-12 mx-auto text-gray-300 dark:text-neutral-600" />
            <p class="mt-3 text-sm text-gray-700 dark:text-neutral-300 font-medium">Belum ada server WHM dengan role mail</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-neutral-400">
                Tambahkan credential WHM di <a href="{{ url('nawasara-vault/credentials') }}" wire:navigate class="text-emerald-700 dark:text-emerald-400 hover:underline font-medium">Vault</a> dan set role = mail.
            </p>
        </div>
    @else
        @php
            $statusOptions = ['active' => 'Active', 'suspended' => 'Suspended'];
        @endphp

        {{-- Page header — title + Tambah Email + sync icon + export. --}}
        <x-nawasara-ui::page-header
            title="Email Accounts"
            description="Manajemen email account cPanel di server mail. Bulk suspend/quota/delete dari sini."
            :count="$this->accounts->total().' email'">
            <a href="{{ url('admin/sync/jobs') }}" wire:navigate
                class="text-xs text-emerald-700 dark:text-emerald-400 hover:underline font-medium whitespace-nowrap">
                Lihat Sync Jobs →
            </a>

            <x-nawasara-ui::icon-button icon="refresh-cw" tooltip="Sync ulang dari WHM" wire:click="refreshList" loadingTarget="refreshList" />

            <x-nawasara-ui::export-button
                action="export"
                tooltip="Ekspor email accounts (active server)"
                permission="whm.email.view" />

            @can('whm.email.create')
                <x-nawasara-ui::button wire:click="$dispatch('openCreateEmail')" color="success"
                    @click="$dispatch('open-modal', 'whm-email-form')">
                    <x-slot:icon><x-lucide-plus class="size-4" /></x-slot:icon>
                    Tambah Email
                </x-nawasara-ui::button>
            @endcan
        </x-nawasara-ui::page-header>

        {{-- Server selector + sync metadata. --}}
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <x-nawasara-whm::server-switcher :servers="$this->servers" role="mail" />
            @if ($this->lastSyncedAt)
                <span class="text-xs text-gray-500 dark:text-neutral-400">
                    <x-lucide-clock class="size-3 inline" /> Last sync: {{ $this->lastSyncedAt }}
                </span>
            @else
                <span class="text-xs text-amber-700 dark:text-amber-400">Belum pernah di-sync.</span>
            @endif
            @if ($this->pendingCount > 0)
                <span class="inline-flex items-center gap-1 text-xs text-cyan-700 dark:text-cyan-400">
                    <x-lucide-loader class="size-3 animate-spin" /> {{ $this->pendingCount }} pending sync
                </span>
            @endif
        </div>

        {{-- Toolbar — Status multi-select + search. --}}
        <div class="space-y-2 mb-4">
            <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
                <div class="flex flex-wrap items-center gap-2 shrink-0">
                    <x-nawasara-ui::filter-panel
                        label="Filter"
                        :state="['statusFilter' => $statusFilter]"
                        :multiple="['statusFilter']"
                        :labels="['statusFilter' => $statusOptions]"
                        :dimensions="['statusFilter' => 'Status']">
                        <x-nawasara-ui::filter-group label="Status" model="statusFilter" :items="$statusOptions" icon="lucide-circle-check" />
                    </x-nawasara-ui::filter-panel>
                </div>

                <x-nawasara-ui::search-input model="search" placeholder="Cari email atau domain..." />
            </div>

            <div wire:ignore data-filter-chips></div>

            @if ($search)
                <div class="flex flex-wrap items-center gap-2">
                    <x-nawasara-ui::filter-chip label="Cari: {{ $search }}" model="search" />
                </div>
            @endif
        </div>

        @can('whm.email.manage')
            <x-nawasara-ui::bulk-action-bar :count="count($selected)" clearAction="resetSelection" label="email dipilih">
                <x-nawasara-ui::button color="warning" variant="outline" size="sm" wire:click="bulkSuspend" wire:confirm="Suspend {{ count($selected) }} email yang dipilih?">
                    <x-slot:icon><x-lucide-pause /></x-slot:icon>
                    Suspend
                </x-nawasara-ui::button>
                <x-nawasara-ui::button color="success" variant="outline" size="sm" wire:click="bulkUnsuspend">
                    <x-slot:icon><x-lucide-play /></x-slot:icon>
                    Unsuspend
                </x-nawasara-ui::button>
                <x-nawasara-ui::button color="primary" variant="outline" size="sm" wire:click="openBulkQuota">
                    <x-slot:icon><x-lucide-database /></x-slot:icon>
                    Set Quota
                </x-nawasara-ui::button>
                <x-nawasara-ui::button color="danger" variant="outline" size="sm" wire:click="bulkDelete" wire:confirm="HAPUS {{ count($selected) }} email? Mail akan hilang permanen!">
                    <x-slot:icon><x-lucide-trash-2 /></x-slot:icon>
                    Delete
                </x-nawasara-ui::button>
            </x-nawasara-ui::bulk-action-bar>
        @endcan

        @php
            $selectAllHeader = '<input type="checkbox" wire:model.live="selectAll" class="size-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 dark:bg-neutral-800 dark:border-neutral-600">';
        @endphp
        <x-nawasara-ui::table stickyLast
            :headers="[$selectAllHeader, 'Email', 'Status', 'Sync', '']">
            <x-slot:table>
                @forelse ($this->accounts as $acct)
                    <tr wire:key="email-{{ $acct->id }}" class="{{ in_array((string) $acct->id, $selected) ? 'bg-blue-50/50 dark:bg-blue-900/10' : '' }}">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <input type="checkbox" wire:model.live="selected" value="{{ $acct->id }}"
                                class="size-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 dark:bg-neutral-800 dark:border-neutral-600">
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-neutral-200 font-mono">
                            {{ $acct->email }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            @if ($acct->isSuspended())
                                <x-nawasara-ui::badge color="danger" dot>Suspended</x-nawasara-ui::badge>
                            @else
                                <x-nawasara-ui::badge color="success" dot>Active</x-nawasara-ui::badge>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <x-nawasara-sync::sync-badge :status="$acct->sync_status" :error="$acct->sync_error" />
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                            <x-nawasara-ui::dropdown-menu-action :id="$acct->id" :items="[
                                ['type' => 'click', 'label' => 'Detail', 'wire:click' => 'openDetail('.$acct->id.')', 'modal' => 'whm-email-detail', 'icon' => 'lucide-eye', 'permission' => 'whm.email.view'],
                                // Admin impersonation — launch Roundcube sebagai user pemilik mailbox.
                                // Permission terpisah (webmail.session.launch_as) supaya bisa di-grant
                                // ke admin tertentu tanpa kasih full whm.email.manage.
                                ['type' => 'click', 'label' => 'Buka Webmail', 'wire:click' => 'openLaunchAs(\'' . $acct->email . '\')', 'modal' => 'whm-email-launch-as', 'icon' => 'lucide-external-link', 'permission' => 'webmail.session.launch_as'],
                                ['type' => 'click', 'label' => 'Change Password', 'wire:click' => 'openChangePassword(\'' . $acct->email . '\')', 'modal' => 'whm-email-password', 'icon' => 'lucide-key-round', 'permission' => 'whm.email.manage'],
                                ['type' => 'click', 'label' => 'Change Quota', 'wire:click' => 'openChangeQuota(\'' . $acct->email . '\', '.($acct->quota_mb ?: 0).')', 'modal' => 'whm-email-quota', 'icon' => 'lucide-database', 'permission' => 'whm.email.manage'],
                                $acct->isSuspended()
                                    ? ['type' => 'click', 'label' => 'Unsuspend', 'wire:click' => 'unsuspend(\'' . $acct->email . '\')', 'icon' => 'lucide-play', 'permission' => 'whm.email.manage']
                                    : ['type' => 'click', 'label' => 'Suspend', 'wire:click' => 'suspend(\'' . $acct->email . '\')', 'icon' => 'lucide-pause', 'confirm' => 'Suspend email ' . $acct->email . '? Login & receive akan dimatikan.', 'permission' => 'whm.email.manage'],
                                ['type' => 'click', 'label' => 'Delete', 'wire:click' => 'delete(\'' . $acct->email . '\')', 'icon' => 'lucide-trash-2', 'confirm' => 'Hapus email ' . $acct->email . '? Semua mail akan hilang permanen!', 'permission' => 'whm.email.manage'],
                            ]" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            @if ($this->lastSyncedAt === null)
                                <x-nawasara-ui::empty-state
                                    icon="lucide-mail"
                                    title="Database mailbox masih kosong"
                                    description="Klik tombol Sync Sekarang untuk fetch email account dari WHM server."
                                    inline />
                            @else
                                <x-nawasara-ui::empty-state
                                    icon="lucide-search-x"
                                    title="Tidak ada mailbox yang cocok"
                                    description="Coba ubah filter atau hapus search keyword."
                                    variant="filter"
                                    inline />
                            @endif
                        </td>
                    </tr>
                @endforelse
            </x-slot:table>

            <x-slot:footer>
                {{ $this->accounts->links() }}
            </x-slot:footer>
        </x-nawasara-ui::table>
    @endif

    {{-- Create Modal --}}
    <x-nawasara-ui::modal id="whm-email-form" maxWidth="lg" title="Tambah Email Account">
        <form wire:submit="save" id="whm-email-form-el" class="space-y-4">
            <div class="grid grid-cols-3 gap-2 items-end">
                <div class="col-span-2">
                    <x-nawasara-ui::form.input label="Local Part" placeholder="dinkes"
                        wire:model="formLocalPart" useError errorVariable="formLocalPart" />
                </div>
                <div>
                    <x-nawasara-ui::form.label value="Domain" />
                    <x-nawasara-ui::form.select wire:model="formDomain" :placeholder="false">
                        @foreach ($this->domains as $d)
                            <option value="{{ $d }}">@{{ $d }}</option>
                        @endforeach
                    </x-nawasara-ui::form.select>
                </div>
            </div>
            @if ($formLocalPart && $formDomain)
                <div class="text-xs text-gray-600 dark:text-neutral-400 -mt-2">
                    Email lengkap: <code class="font-mono">{{ $formLocalPart }}@{{ $formDomain }}</code>
                </div>
            @endif

            <div>
                <div class="flex items-center justify-between mb-1">
                    <x-nawasara-ui::form.label value="Password" />
                    <x-nawasara-ui::button variant="link" color="primary" size="sm"
                        wire:click="generatePassword"
                        class="text-xs">
                        Generate
                    </x-nawasara-ui::button>
                </div>
                <x-nawasara-ui::form.input type="password" wire:model="formPassword"
                    usePasswordField useError errorVariable="formPassword" />
            </div>

            <x-nawasara-ui::form.input label="Quota (MB)" type="number" placeholder="250"
                wire:model="formQuota" useError errorVariable="formQuota" />
            <p class="text-xs text-gray-500 dark:text-neutral-400 -mt-2">
                Isi <code class="font-mono">0</code> untuk unlimited
            </p>
        </form>
        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" @click="$dispatch('close-modal', 'whm-email-form')">Batal</x-nawasara-ui::button>
            <x-nawasara-ui::button type="submit" form="whm-email-form-el" color="primary">Simpan</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>

    {{-- Change Password Modal --}}
    <x-nawasara-ui::modal id="whm-email-password" maxWidth="md" :title="'Ganti Password: '.$pwEmail">
        <form wire:submit="doChangePassword" id="whm-email-password-form" class="space-y-4">
            <div>
                <div class="flex items-center justify-between mb-1">
                    <x-nawasara-ui::form.label value="Password Baru" />
                    <x-nawasara-ui::button variant="link" color="primary" size="sm"
                        wire:click="generatePasswordReset"
                        class="text-xs">
                        Generate
                    </x-nawasara-ui::button>
                </div>
                <x-nawasara-ui::form.input type="password" wire:model="pwNewPassword"
                    usePasswordField useError errorVariable="pwNewPassword" />
            </div>
        </form>
        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" @click="$dispatch('close-modal', 'whm-email-password')">Batal</x-nawasara-ui::button>
            <x-nawasara-ui::button type="submit" form="whm-email-password-form" color="primary">Ubah Password</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>

    {{-- Change Quota Modal --}}
    <x-nawasara-ui::modal id="whm-email-quota" maxWidth="md" :title="'Ubah Quota: '.$quotaEmail">
        <form wire:submit="doChangeQuota" id="whm-email-quota-form" class="space-y-4">
            <x-nawasara-ui::form.input label="Quota Baru (MB)" type="number"
                wire:model="quotaNew" useError errorVariable="quotaNew" />
            <p class="text-xs text-gray-500 dark:text-neutral-400">
                Isi <code class="font-mono">0</code> untuk unlimited
            </p>
        </form>
        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" @click="$dispatch('close-modal', 'whm-email-quota')">Batal</x-nawasara-ui::button>
            <x-nawasara-ui::button type="submit" form="whm-email-quota-form" color="primary">Ubah Quota</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>

    {{-- Bulk Quota Modal --}}
    <x-nawasara-ui::modal id="whm-email-bulk-quota" maxWidth="md" :title="'Bulk Set Quota: '.count($selected).' email'">
        <form wire:submit="doBulkQuota" id="whm-email-bulk-quota-form" class="space-y-4">
            <x-nawasara-ui::form.input label="Quota Baru (MB)" type="number"
                wire:model="bulkQuotaNew" useError errorVariable="bulkQuotaNew" />
            <p class="text-xs text-gray-500 dark:text-neutral-400">
                Quota ini akan di-apply ke <strong>{{ count($selected) }}</strong> email account. Isi <code class="font-mono">0</code> untuk unlimited.
            </p>
        </form>
        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" @click="$dispatch('close-modal', 'whm-email-bulk-quota')">Batal</x-nawasara-ui::button>
            <x-nawasara-ui::button type="submit" form="whm-email-bulk-quota-form" color="primary">Apply ke Semua</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>

    {{-- Launch-as (Admin Impersonation) Modal — buka Roundcube sebagai pemilik
         mailbox tanpa tahu password mereka. Wajib isi alasan supaya audit
         trail actionable.

         Tab pattern (popup-blocker safe):
           1. User klik [Buka Webmail] di footer → JS pre-open about:blank di
              tab baru (SYNC dari user click context — browser allow).
           2. Reference tab disimpan di window scope, lalu form submit normal
              ke Livewire.
           3. Livewire response dispatch event `webmail-launch-window` dengan
              URL session → JS listener update tab.location.href.
           4. Kalau Livewire error / event tidak fire → tab pre-opened tetap
              about:blank, user lihat blank page. --}}
    <x-nawasara-ui::modal id="whm-email-launch-as" maxWidth="lg" :title="'Buka Webmail: '.$launchAsEmail">
        <form wire:submit="confirmLaunchAs" id="whm-email-launch-as-form" class="space-y-4">
            <div class="rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800/50 p-3 text-sm text-amber-800 dark:text-amber-200">
                <div class="flex gap-2">
                    <x-lucide-shield-alert class="size-5 shrink-0 mt-0.5" />
                    <div>
                        <p class="font-medium">Anda akan masuk ke webmail sebagai pemilik <code class="font-mono">{{ $launchAsEmail }}</code>.</p>
                        <p class="mt-1 text-xs">Akses ini dicatat dalam audit log dengan timestamp, IP, dan alasan yang Anda isi. Atasan dapat melihat aktivitas ini.</p>
                    </div>
                </div>
            </div>

            <div>
                <x-nawasara-ui::form.label>
                    Alasan akses <span class="text-red-500">*</span>
                </x-nawasara-ui::form.label>
                <textarea wire:model="launchAsReason" rows="3"
                    class="block w-full rounded-lg border-gray-200 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 text-sm focus:border-emerald-600 focus:ring-emerald-600"
                    placeholder="Contoh: User meminta bantuan reset filter spam yang block email penting"></textarea>
                @error('launchAsReason')
                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                @enderror
                <p class="text-xs text-gray-500 dark:text-neutral-400 mt-1">Minimal 10 karakter. Semakin spesifik semakin baik untuk audit.</p>
            </div>
        </form>

        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" @click="$dispatch('close-modal', 'whm-email-launch-as')">Batal</x-nawasara-ui::button>
            {{-- onclick INTENTIONALLY tidak pakai noopener,noreferrer karena
                 itu bikin window.open() return null — kita tidak bisa
                 update tab.location.href di event listener nanti. Pakai
                 explicit `tab.opener = null` setelah URL update sebagai
                 substitute (lihat <script> di bawah). --}}
            <x-nawasara-ui::button
                type="submit"
                form="whm-email-launch-as-form"
                color="warning"
                onclick="window.__nawasaraWebmailLaunchTab = window.open('about:blank', '_blank')">
                <x-slot:icon><x-lucide-external-link /></x-slot:icon>
                Buka Webmail
            </x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>

    {{-- JS bridge: update URL tab pre-opened dengan session URL. Setelah
         URL update, null out opener untuk security (substitute for noopener
         yang tidak bisa kita pakai di pre-open). Fallback window.open
         kalau tab reference hilang/closed. --}}
    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('webmail-launch-window', (event) => {
                const payload = Array.isArray(event) ? event[0] : event;
                const url = payload?.url;
                if (! url) return;

                const tab = window.__nawasaraWebmailLaunchTab;
                if (tab && ! tab.closed) {
                    tab.location.href = url;
                    try { tab.opener = null; } catch (e) { /* cross-origin */ }
                } else {
                    window.open(url, '_blank', 'noopener,noreferrer');
                }
                window.__nawasaraWebmailLaunchTab = null;
            });
        });
    </script>

    {{-- Detail Modal --}}
    <x-nawasara-ui::modal id="whm-email-detail" maxWidth="lg" :title="'Detail: '.($this->detail->email ?? '')">
        @if ($this->detail)
            @php $d = $this->detail; @endphp
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div><span class="text-gray-500 dark:text-neutral-400">Email:</span> <span class="font-medium font-mono text-gray-800 dark:text-neutral-200">{{ $d->email }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Domain:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $d->domain }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">cPanel User:</span> <span class="font-medium font-mono text-gray-800 dark:text-neutral-200">{{ $d->cpanel_user }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Server:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $d->instance }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Quota:</span> <span class="font-medium font-mono text-gray-800 dark:text-neutral-200">{{ $d->quota_mb === null ? 'Unlimited' : $d->quota_mb.' MB' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Disk Used:</span> <span class="font-medium font-mono text-gray-800 dark:text-neutral-200">{{ $d->disk_used_mb !== null ? $d->disk_used_mb.' MB' : '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Suspended Login:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $d->suspended_login ? 'Ya' : 'Tidak' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Suspended Incoming:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $d->suspended_incoming ? 'Ya' : 'Tidak' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Sync Status:</span>
                    <x-nawasara-sync::sync-badge :status="$d->sync_status" :error="$d->sync_error" />
                </div>
                <div><span class="text-gray-500 dark:text-neutral-400">Last Synced:</span> <span class="text-gray-800 dark:text-neutral-200">{{ $d->last_synced_at?->diffForHumans() ?? '-' }}</span></div>
                @if ($d->sync_error)
                    <div class="col-span-2">
                        <span class="text-gray-500 dark:text-neutral-400">Error:</span>
                        <p class="text-xs text-red-600 dark:text-red-400 mt-1 font-mono">{{ $d->sync_error }}</p>
                    </div>
                @endif
            </div>
        @endif
        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" @click="$dispatch('close-modal', 'whm-email-detail')">Tutup</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>
</div>
