<div>
    @if (! count($this->servers))
        <div class="text-center py-12 border-2 border-dashed border-gray-200 dark:border-neutral-700 rounded-xl">
            <x-lucide-server class="size-12 mx-auto text-gray-300 dark:text-neutral-600" />
            <p class="mt-3 text-sm text-gray-700 dark:text-neutral-300 font-medium">Belum ada server WHM dikonfigurasi</p>
        </div>
    @else
        {{-- Page header — title + Tambah Package. Packages are live data
             from WHM API; no export (small dataset, source-of-truth at
             WHM dashboard). --}}
        <x-nawasara-ui::page-header
            title="Hosting Packages"
            description="Hosting package definition di server WHM. Hapus package gagal kalau masih dipakai akun."
            :count="count($this->packages).' packages'">
            @can('whm.package.manage')
                <x-nawasara-ui::button wire:click="$dispatch('openCreatePackage')" color="success"
                    @click="$dispatch('open-modal', 'whm-package-form')">
                    <x-slot:icon><x-lucide-plus class="size-4" /></x-slot:icon>
                    Tambah Package
                </x-nawasara-ui::button>
            @endcan
        </x-nawasara-ui::page-header>

        {{-- Server selector + search. Server is hard scope. --}}
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <x-nawasara-whm::server-switcher :servers="$this->servers" role="hosting" />
        </div>

        <x-nawasara-ui::search-input model="search" placeholder="Cari nama package..." layout="block" class="mb-4" />

        <x-nawasara-ui::table stickyLast
            :headers="['Nama', 'Disk', 'Bandwidth', 'Email', 'FTP', 'SQL', 'Subdomain', '']">
            <x-slot:table>
                @forelse ($this->packages as $pkg)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-neutral-200">{{ $pkg['name'] ?? '-' }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-neutral-400 font-mono">{{ $pkg['QUOTA'] ?? '-' }} MB</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-neutral-400 font-mono">{{ $pkg['BWLIMIT'] ?? '-' }} MB</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-neutral-400 font-mono">{{ $pkg['MAXPOP'] ?? '-' }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-neutral-400 font-mono">{{ $pkg['MAXFTP'] ?? '-' }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-neutral-400 font-mono">{{ $pkg['MAXSQL'] ?? '-' }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-neutral-400 font-mono">{{ $pkg['MAXSUB'] ?? '-' }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                            <x-nawasara-ui::dropdown-menu-action :id="$pkg['name']" :items="[
                                ['type' => 'click', 'label' => 'Hapus', 'wire:click' => 'deletePackage(\'' . $pkg['name'] . '\')', 'icon' => 'lucide-trash-2', 'confirm' => 'Yakin hapus package ini? Gagal jika masih dipakai akun.', 'permission' => 'whm.package.manage'],
                            ]" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-6 py-8 text-center text-sm text-gray-500 dark:text-neutral-400">
                            Tidak ada package ditemukan.
                        </td>
                    </tr>
                @endforelse
            </x-slot:table>
        </x-nawasara-ui::table>
    @endif

    {{-- Create Modal --}}
    <x-nawasara-ui::modal id="whm-package-form" maxWidth="lg" title="Tambah Package">
        <form wire:submit="savePackage" id="whm-package-form-el" class="space-y-4">
            <x-nawasara-ui::form.input label="Nama Package" placeholder="starter, standard, premium"
                wire:model="formName" useError errorVariable="formName" />

            <div class="grid grid-cols-2 gap-4">
                <x-nawasara-ui::form.input label="Quota Disk (MB)" type="number" wire:model="formQuota" placeholder="5000" />
                <x-nawasara-ui::form.input label="Bandwidth (MB)" type="number" wire:model="formBwLimit" placeholder="50000" />
            </div>

            <div class="grid grid-cols-3 gap-4">
                <x-nawasara-ui::form.input label="Max Email" type="number" wire:model="formMaxPop" />
                <x-nawasara-ui::form.input label="Max FTP" type="number" wire:model="formMaxFtp" />
                <x-nawasara-ui::form.input label="Max SQL" type="number" wire:model="formMaxSql" />
                <x-nawasara-ui::form.input label="Max Subdomain" type="number" wire:model="formMaxSub" />
                <x-nawasara-ui::form.input label="Max Addon" type="number" wire:model="formMaxAddon" />
                <x-nawasara-ui::form.input label="Max Parked" type="number" wire:model="formMaxPark" />
            </div>

            <p class="text-xs text-gray-500 dark:text-neutral-400">
                Gunakan <code class="font-mono">unlimited</code> untuk tanpa batas. Ukuran dalam MB.
            </p>
        </form>
        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" @click="$dispatch('close-modal', 'whm-package-form')">Batal</x-nawasara-ui::button>
            <x-nawasara-ui::button type="submit" form="whm-package-form-el" color="primary">Simpan</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>
</div>
