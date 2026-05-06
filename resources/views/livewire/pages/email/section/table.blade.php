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
        {{-- Sync info bar --}}
        <div class="mb-3 flex items-center justify-between text-xs text-gray-500 dark:text-neutral-400">
            <div class="flex items-center gap-3">
                @if ($this->lastSyncedAt)
                    <span><x-lucide-clock class="size-3 inline" /> Last sync: {{ $this->lastSyncedAt }}</span>
                @else
                    <span class="text-amber-700 dark:text-amber-400">Belum pernah di-sync. Klik "Sync Sekarang" untuk fetch data dari WHM.</span>
                @endif
                @if ($this->pendingCount > 0)
                    <span class="text-cyan-700 dark:text-cyan-400 animate-pulse">
                        <x-lucide-clock class="size-3 inline" /> {{ $this->pendingCount }} pending sync
                    </span>
                @endif
            </div>
            <a href="{{ url('admin/sync/jobs') }}" wire:navigate class="text-emerald-700 dark:text-emerald-400 hover:underline font-medium">
                Lihat Sync Jobs →
            </a>
        </div>

        <x-nawasara-ui::filter-bar searchPlaceholder="Cari email, domain..." searchModel="search">
            <x-nawasara-whm::server-switcher :servers="$this->servers" role="mail" />

            <x-nawasara-ui::filter-dropdown label="Status" model="statusFilter"
                :items="['all' => 'Semua Status', 'active' => 'Active', 'suspended' => 'Suspended']" />

            <x-slot:actions>
                <x-nawasara-ui::button color="neutral" variant="outline" size="sm" wire:click="refreshList">
                    <x-slot:icon>
                        <x-lucide-refresh-cw wire:loading.class="animate-spin" wire:target="refreshList" />
                    </x-slot:icon>
                    Sync Sekarang
                </x-nawasara-ui::button>
            </x-slot:actions>

            <x-slot:chips>
                @if ($statusFilter)
                    <x-nawasara-ui::filter-chip label="Status: {{ ucfirst($statusFilter) }}" model="statusFilter" />
                @endif
                @if ($search)
                    <x-nawasara-ui::filter-chip label="Cari: {{ $search }}" model="search" />
                @endif
            </x-slot:chips>
        </x-nawasara-ui::filter-bar>

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
        <x-nawasara-ui::table
            :headers="[$selectAllHeader, 'Email', 'Status', 'Sync', '']"
            :title="'Email Accounts (' . $this->accounts->total() . ')'">
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
                        <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500 dark:text-neutral-400">
                            @if ($this->lastSyncedAt === null)
                                Database masih kosong. Klik <strong>Sync Sekarang</strong> untuk fetch dari WHM.
                            @else
                                Tidak ada email account ditemukan untuk filter ini.
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
