<div>
    @if (! count($this->servers))
        <div class="text-center py-12 border-2 border-dashed border-gray-200 dark:border-neutral-700 rounded-xl">
            <x-lucide-server class="size-12 mx-auto text-gray-300 dark:text-neutral-600" />
            <p class="mt-3 text-sm text-gray-700 dark:text-neutral-300 font-medium">Belum ada server WHM dikonfigurasi</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-neutral-400">
                Tambahkan credential WHM di <a href="{{ url('nawasara-vault/credentials') }}" wire:navigate class="text-blue-600 hover:underline">Vault</a> untuk mulai menggunakan fitur ini.
            </p>
        </div>
    @else
        <x-nawasara-ui::filter-bar searchPlaceholder="Cari user, domain, email..." searchModel="search">
            @if (count($this->servers) > 1)
                <x-nawasara-ui::filter-dropdown label="Server" model="server" :items="$this->serverOptions" />
            @endif

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
                    Refresh
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

        <x-nawasara-ui::table
            :headers="['Username', 'Domain', 'OPD / PIC', 'Package', 'Disk', 'Status', '']"
            :title="'cPanel Accounts (' . count($this->accounts) . ')'">
            <x-slot:table>
                @forelse ($this->accounts as $acct)
                    @php
                        $username = $acct['user'] ?? '';
                        $asset = $this->assetMap[$username] ?? null;
                        $suspended = ($acct['suspended'] ?? 0) == 1;
                        $diskUsed = $acct['diskused'] ?? '-';
                        $diskLimit = $acct['disklimit'] ?? 'unlimited';
                    @endphp
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-neutral-200 font-mono">
                            {{ $username }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-neutral-300">
                            {{ $acct['domain'] ?? '-' }}
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
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-50 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">
                                    Belum ditetapkan
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500 dark:bg-neutral-700 dark:text-neutral-400">
                                    Belum di-sync
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-50 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400">
                                {{ $acct['plan'] ?? '-' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-neutral-400 font-mono">
                            {{ $diskUsed }} / {{ $diskLimit }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            @if ($suspended)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                    Suspended
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                    Active
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                            <x-nawasara-ui::dropdown-menu-action :id="$username" :items="[
                                ['type' => 'click', 'label' => 'Detail', 'wire:click' => 'openDetail(\'' . $username . '\')', 'modal' => 'whm-account-detail', 'icon' => 'lucide-eye', 'permission' => 'whm.account.view'],
                                ['type' => 'click', 'label' => 'Change Password', 'wire:click' => 'openChangePassword(\'' . $username . '\')', 'modal' => 'whm-password', 'icon' => 'lucide-key-round', 'permission' => 'whm.account.manage'],
                                $suspended
                                    ? ['type' => 'click', 'label' => 'Unsuspend', 'wire:click' => 'unsuspend(\'' . $username . '\')', 'icon' => 'lucide-play', 'permission' => 'whm.account.suspend']
                                    : ['type' => 'click', 'label' => 'Suspend', 'wire:click' => 'openSuspend(\'' . $username . '\')', 'modal' => 'whm-suspend', 'icon' => 'lucide-pause', 'permission' => 'whm.account.suspend'],
                                ['type' => 'click', 'label' => 'Terminate', 'wire:click' => 'terminate(\'' . $username . '\')', 'icon' => 'lucide-trash-2', 'confirm' => 'Yakin hapus akun ini permanen? Semua data akan hilang!', 'permission' => 'whm.account.terminate'],
                            ]" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500 dark:text-neutral-400">
                            @if (! $this->client()?->isConfigured())
                                Credential WHM belum lengkap. Periksa Vault.
                            @else
                                Tidak ada akun ditemukan.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </x-slot:table>
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
    <x-nawasara-ui::modal id="whm-account-detail" maxWidth="2xl" :title="'Detail: '.($detailAccount['user'] ?? '')">
        @if ($detailAccount)
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div><span class="text-gray-500 dark:text-neutral-400">Username:</span> <span class="font-medium text-gray-800 dark:text-neutral-200 font-mono">{{ $detailAccount['user'] ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Domain:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $detailAccount['domain'] ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Email:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $detailAccount['email'] ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">IP:</span> <span class="font-medium text-gray-800 dark:text-neutral-200 font-mono">{{ $detailAccount['ip'] ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Package:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $detailAccount['plan'] ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Theme:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $detailAccount['theme'] ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Disk Used:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $detailAccount['diskused'] ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Disk Limit:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $detailAccount['disklimit'] ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Inodes Used:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $detailAccount['inodesused'] ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Inode Limit:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $detailAccount['inodeslimit'] ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Unix Start Date:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ $detailAccount['unix_startdate'] ?? '-' }}</span></div>
                <div><span class="text-gray-500 dark:text-neutral-400">Suspended:</span> <span class="font-medium text-gray-800 dark:text-neutral-200">{{ ($detailAccount['suspended'] ?? 0) == 1 ? 'Ya' : 'Tidak' }}</span></div>
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

    {{-- Suspend Modal --}}
    <x-nawasara-ui::modal id="whm-suspend" maxWidth="md" :title="'Suspend: '.$suspendUsername">
        <form wire:submit="doSuspend" id="whm-suspend-form" class="space-y-4">
            <x-nawasara-ui::form.textarea label="Alasan (opsional)" wire:model="suspendReason"
                placeholder="Contoh: Telat bayar, melanggar ToS, dll." rows="3" />
            <p class="text-xs text-yellow-600 dark:text-yellow-400">
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
