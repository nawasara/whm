<div>
    @if (! count($this->servers))
        <div class="text-center py-12 border-2 border-dashed border-gray-200 dark:border-neutral-700 rounded-xl">
            <x-lucide-server class="size-12 mx-auto text-gray-300 dark:text-neutral-600" />
            <p class="mt-3 text-sm text-gray-700 dark:text-neutral-300 font-medium">Belum ada server WHM dikonfigurasi</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-neutral-400">
                Tambahkan credential WHM di <a href="{{ url('nawasara-vault/credentials') }}" wire:navigate class="text-emerald-700 dark:text-emerald-400 hover:underline font-medium">Vault</a> untuk mulai menggunakan fitur ini.
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

        <x-nawasara-ui::filter-bar searchPlaceholder="Cari user, domain, email..." searchModel="search">
            <x-nawasara-whm::server-switcher :servers="$this->servers" role="hosting" />

            <x-nawasara-ui::filter-dropdown label="Status" model="statusFilter"
                :items="['all' => 'Semua Status', 'active' => 'Active', 'suspended' => 'Suspended']" />

            @if (! empty($this->packageOptions))
                <x-nawasara-ui::filter-dropdown label="Package" model="packageFilter"
                    :items="array_merge(['all' => 'Semua Package'], $this->packageOptions)" />
            @endif

            <x-slot:actions>
                <x-nawasara-ui::button color="neutral" variant="outline" size="sm" wire:click="refreshAccounts">
                    <x-slot:icon>
                        <x-lucide-refresh-cw wire:loading.class="animate-spin" wire:target="refreshAccounts" />
                    </x-slot:icon>
                    Sync Sekarang
                </x-nawasara-ui::button>
            </x-slot:actions>

            <x-slot:chips>
                @if ($statusFilter)
                    <x-nawasara-ui::filter-chip label="Status: {{ ucfirst($statusFilter) }}" model="statusFilter" />
                @endif
                @if ($packageFilter)
                    <x-nawasara-ui::filter-chip label="Package: {{ $packageFilter }}" model="packageFilter" />
                @endif
                @if ($search)
                    <x-nawasara-ui::filter-chip label="Cari: {{ $search }}" model="search" />
                @endif
            </x-slot:chips>
        </x-nawasara-ui::filter-bar>

        @can('whm.account.suspend')
            <x-nawasara-ui::bulk-action-bar :count="count($selected)" clearAction="resetSelection" label="akun dipilih">
                <x-nawasara-ui::button color="warning" variant="outline" size="sm" wire:click="openBulkSuspend">
                    <x-slot:icon><x-lucide-pause /></x-slot:icon>
                    Suspend
                </x-nawasara-ui::button>
                <x-nawasara-ui::button color="success" variant="outline" size="sm" wire:click="bulkUnsuspend">
                    <x-slot:icon><x-lucide-play /></x-slot:icon>
                    Unsuspend
                </x-nawasara-ui::button>
            </x-nawasara-ui::bulk-action-bar>
        @endcan

        @php
            $selectAllHeader = '<input type="checkbox" wire:model.live="selectAll" class="size-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 dark:bg-neutral-800 dark:border-neutral-600">';
        @endphp
        <x-nawasara-ui::table
            :headers="[$selectAllHeader, 'Username', 'Domain', 'OPD / PIC', 'Package', 'Disk', 'Status', 'Sync', '']"
            :title="'cPanel Accounts (' . $this->accounts->total() . ')'">
            <x-slot:table>
                @forelse ($this->accounts as $acct)
                    @php $asset = $this->assetMap[$acct->username] ?? null; @endphp
                    <tr wire:key="account-{{ $acct->id }}" class="{{ in_array((string) $acct->id, $selected) ? 'bg-blue-50/50 dark:bg-blue-900/10' : '' }}">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <input type="checkbox" wire:model.live="selected" value="{{ $acct->id }}"
                                class="size-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 dark:bg-neutral-800 dark:border-neutral-600">
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-neutral-200 font-mono">
                            {{ $acct->username }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-neutral-300">
                            {{ $acct->domain ?? '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            @if ($asset && $asset->opd)
                                <div class="flex flex-col">
                                    <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $asset->opd->name }}</span>
                                    @if ($asset->pic)
                                        <span class="text-xs text-gray-500 dark:text-neutral-400">PIC: {{ $asset->pic->name }}</span>
                                    @endif
                                </div>
                            @elseif ($asset)
                                <x-nawasara-ui::badge color="warning">Belum ditetapkan</x-nawasara-ui::badge>
                            @else
                                <x-nawasara-ui::badge color="neutral">Belum di-link</x-nawasara-ui::badge>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <x-nawasara-ui::badge color="info">
                                {{ $acct->plan ?? '-' }}
                            </x-nawasara-ui::badge>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-neutral-400 font-mono">
                            @php
                                $diskUsed = $acct->humanized['diskused'] ?? ($acct->disk_used_mb !== null ? $acct->disk_used_mb.'M' : '-');
                                $diskLimit = $acct->humanized['disklimit'] ?? ($acct->disk_limit_mb ? $acct->disk_limit_mb.'M' : 'unlimited');
                            @endphp
                            {{ $diskUsed }} / {{ $diskLimit }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            @if ($acct->suspended)
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
                                ['type' => 'click', 'label' => 'Detail', 'wire:click' => 'openDetail('.$acct->id.')', 'modal' => 'whm-account-detail', 'icon' => 'lucide-eye', 'permission' => 'whm.account.view'],
                                ['type' => 'click', 'label' => 'Change Password', 'wire:click' => 'openChangePassword(\'' . $acct->username . '\')', 'modal' => 'whm-password', 'icon' => 'lucide-key-round', 'permission' => 'whm.account.manage'],
                                $acct->suspended
                                    ? ['type' => 'click', 'label' => 'Unsuspend', 'wire:click' => 'unsuspend(\'' . $acct->username . '\')', 'icon' => 'lucide-play', 'permission' => 'whm.account.suspend']
                                    : ['type' => 'click', 'label' => 'Suspend', 'wire:click' => 'openSuspend(\'' . $acct->username . '\')', 'modal' => 'whm-suspend', 'icon' => 'lucide-pause', 'permission' => 'whm.account.suspend'],
                                ['type' => 'click', 'label' => 'Terminate', 'wire:click' => 'terminate(\'' . $acct->username . '\')', 'icon' => 'lucide-trash-2', 'confirm' => 'Yakin hapus akun ini permanen? Semua data akan hilang!', 'permission' => 'whm.account.terminate'],
                            ]" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-6 py-8 text-center text-sm text-gray-500 dark:text-neutral-400">
                            @if ($this->lastSyncedAt === null)
                                Database masih kosong. Klik <strong>Sync Sekarang</strong> untuk fetch dari WHM.
                            @else
                                Tidak ada akun ditemukan untuk filter ini.
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
    <x-nawasara-ui::modal id="whm-account-form" maxWidth="lg" title="Tambah Akun cPanel">
        <form wire:submit="saveAccount" id="whm-account-form-el" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <x-nawasara-ui::form.input label="Username" placeholder="opd1" wire:model="formUsername"
                    useError errorVariable="formUsername" />
                <x-nawasara-ui::form.input label="Domain" placeholder="opd1.ponorogo.go.id" wire:model="formDomain"
                    useError errorVariable="formDomain" />
            </div>

            <x-nawasara-ui::form.input label="Email Kontak" type="email" placeholder="admin@opd1.go.id"
                wire:model="formEmail" useError errorVariable="formEmail" />

            <x-nawasara-ui::form.input label="Password" type="password" wire:model="formPassword"
                usePasswordField useError errorVariable="formPassword" />

            <x-nawasara-ui::form.select label="Package" wire:model="formPackage" name="formPackage" placeholder="Pilih package">
                @foreach ($this->packageOptions as $pkgName)
                    <option value="{{ $pkgName }}">{{ $pkgName }}</option>
                @endforeach
            </x-nawasara-ui::form.select>

            <div class="grid grid-cols-2 gap-4 pt-4 border-t border-gray-200 dark:border-neutral-700">
                <div>
                    <x-nawasara-ui::form.label value="OPD (opsional)" />
                    <x-nawasara-ui::form.select wire:model.live="formOpdId" placeholder="-- Pilih OPD --">
                        @foreach (\Nawasara\Registry\Models\Opd::orderBy('name')->get(['id', 'name', 'code']) as $opd)
                            <option value="{{ $opd->id }}">{{ $opd->code }} - {{ $opd->name }}</option>
                        @endforeach
                    </x-nawasara-ui::form.select>
                </div>
                <div>
                    <x-nawasara-ui::form.label value="PIC (opsional)" />
                    <x-nawasara-ui::form.select wire:model="formPicId" placeholder="-- Pilih PIC --">
                        @if ($formOpdId)
                            @foreach (\Nawasara\Registry\Models\Pic::where('opd_id', $formOpdId)->orderBy('name')->get(['id', 'name']) as $pic)
                                <option value="{{ $pic->id }}">{{ $pic->name }}</option>
                            @endforeach
                        @endif
                    </x-nawasara-ui::form.select>
                </div>
            </div>
        </form>

        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" @click="$dispatch('close-modal', 'whm-account-form')">Batal</x-nawasara-ui::button>
            <x-nawasara-ui::button type="submit" form="whm-account-form-el" color="primary">Simpan</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>

    {{-- Detail Modal --}}
    <x-nawasara-ui::modal id="whm-account-detail" maxWidth="2xl" :title="'Detail: '.($this->detail?->username ?? '')">
        @if ($this->detail)
            @php $d = $this->detail; @endphp
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div><span class="text-gray-500 dark:text-neutral-400">Username:</span> <span class="font-medium font-mono text-gray-800 dark:text-neutral-200">{{ $d->username }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Domain:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $d->domain }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Email:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $d->email ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">IP:</span> <span class="font-medium font-mono text-gray-800 dark:text-neutral-200">{{ $d->ip ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Package:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $d->plan ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Owner:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $d->owner ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Disk Used:</span> <span class="font-medium font-mono text-gray-800 dark:text-neutral-200">{{ $d->humanized['diskused'] ?? ($d->disk_used_mb !== null ? $d->disk_used_mb.' MB' : '-') }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Disk Limit:</span> <span class="font-medium font-mono text-gray-800 dark:text-neutral-200">{{ $d->disk_limit_mb ? $d->disk_limit_mb.' MB' : 'Unlimited' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Bandwidth Used:</span> <span class="font-medium font-mono text-gray-800 dark:text-neutral-200">{{ $d->bandwidth_used_mb !== null ? $d->bandwidth_used_mb.' MB' : '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Bandwidth Limit:</span> <span class="font-medium font-mono text-gray-800 dark:text-neutral-200">{{ $d->bandwidth_limit_mb ? $d->bandwidth_limit_mb.' MB' : 'Unlimited' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Inodes:</span> <span class="font-medium font-mono text-gray-800 dark:text-neutral-200">{{ $d->inodes_used ?? '-' }} / {{ $d->inodes_limit ?? 'Unlimited' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Status:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $d->suspended ? 'Suspended' : 'Active' }}</span></div>
                @if ($d->suspend_reason)
                    <div class="col-span-2"><span class="text-gray-500 dark:text-neutral-400">Suspend Reason:</span> <span class="text-gray-800 dark:text-neutral-200">{{ $d->suspend_reason }}</span></div>
                @endif
                <div><span class="text-gray-500 dark:text-neutral-400">Created:</span> <span class="text-gray-800 dark:text-neutral-200">{{ $d->start_date?->format('d M Y') ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Sync Status:</span>
                    <x-nawasara-sync::sync-badge :status="$d->sync_status" :error="$d->sync_error" />
                </div>
                <div><span class="text-gray-500 dark:text-neutral-400">Last Synced:</span> <span class="text-gray-800 dark:text-neutral-200">{{ $d->last_synced_at?->diffForHumans() ?? '-' }}</span></div>
            </div>
        @endif
        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" wire:click="closeDetail">Tutup</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>

    {{-- Change Password Modal --}}
    <x-nawasara-ui::modal id="whm-password" maxWidth="md" :title="'Ganti Password: '.$pwUsername">
        <form wire:submit="doChangePassword" id="whm-password-form" class="space-y-4">
            <x-nawasara-ui::form.input label="Password Baru" type="password" wire:model="pwNewPassword"
                usePasswordField useError errorVariable="pwNewPassword" />
            <p class="text-xs text-gray-500 dark:text-neutral-400">
                Minimal 8 karakter. Gunakan kombinasi huruf, angka, dan simbol untuk keamanan.
            </p>
        </form>
        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" @click="$dispatch('close-modal', 'whm-password')">Batal</x-nawasara-ui::button>
            <x-nawasara-ui::button type="submit" form="whm-password-form" color="primary">Ubah Password</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>

    {{-- Bulk Suspend Modal --}}
    <x-nawasara-ui::modal id="whm-bulk-suspend" maxWidth="md" :title="'Bulk Suspend: '.count($selected).' akun'">
        <form wire:submit="doBulkSuspend" id="whm-bulk-suspend-form" class="space-y-4">
            <x-nawasara-ui::form.textarea label="Alasan (opsional)" wire:model="bulkSuspendReason"
                placeholder="Contoh: Telat bayar, melanggar ToS, dll." rows="3" />
            <p class="text-xs text-amber-700 dark:text-amber-400">
                <x-lucide-alert-triangle class="size-4 inline -mt-0.5" />
                <strong>{{ count($selected) }}</strong> akun akan di-suspend — website + email mati untuk semua akun ini.
            </p>
        </form>
        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" @click="$dispatch('close-modal', 'whm-bulk-suspend')">Batal</x-nawasara-ui::button>
            <x-nawasara-ui::button type="submit" form="whm-bulk-suspend-form" color="danger">Suspend Semua</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>

    {{-- Suspend Modal --}}
    <x-nawasara-ui::modal id="whm-suspend" maxWidth="md" :title="'Suspend: '.$suspendUsername">
        <form wire:submit="doSuspend" id="whm-suspend-form" class="space-y-4">
            <x-nawasara-ui::form.textarea label="Alasan (opsional)" wire:model="suspendReason"
                placeholder="Contoh: Telat bayar, melanggar ToS, dll." rows="3" />
            <p class="text-xs text-amber-700 dark:text-amber-400">
                <x-lucide-alert-triangle class="size-4 inline -mt-0.5" />
                Akun akan di-suspend — website tidak bisa diakses, email tidak bisa terima/kirim.
            </p>
        </form>
        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" @click="$dispatch('close-modal', 'whm-suspend')">Batal</x-nawasara-ui::button>
            <x-nawasara-ui::button type="submit" form="whm-suspend-form" color="danger">Suspend</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>
</div>
