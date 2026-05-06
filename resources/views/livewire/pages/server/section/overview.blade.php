<div class="space-y-6">
    @if (! count($this->servers))
        <div class="text-center py-12 border-2 border-dashed border-gray-200 dark:border-neutral-700 rounded-xl">
            <x-lucide-server class="size-12 mx-auto text-gray-300 dark:text-neutral-600" />
            <p class="mt-3 text-sm text-gray-700 dark:text-neutral-300 font-medium">Belum ada server WHM dikonfigurasi</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-neutral-400">
                Tambahkan credential WHM di <a href="{{ url('nawasara-vault/credentials') }}" wire:navigate class="text-emerald-700 dark:text-emerald-400 hover:underline font-medium">Vault</a>.
            </p>
        </div>
    @else
        @if (count($this->servers) > 1)
            <div class="flex items-center justify-between">
                <x-nawasara-whm::server-switcher :servers="$this->servers" />
                <x-nawasara-ui::button color="neutral" variant="outline" size="sm" wire:click="refresh">
                    <x-slot:icon>
                        <x-lucide-refresh-cw wire:loading.class="animate-spin" wire:target="refresh" />
                    </x-slot:icon>
                    Refresh
                </x-nawasara-ui::button>
            </div>
        @else
            <div class="flex justify-end">
                <x-nawasara-ui::button color="neutral" variant="outline" size="sm" wire:click="refresh">
                    <x-slot:icon>
                        <x-lucide-refresh-cw wire:loading.class="animate-spin" wire:target="refresh" />
                    </x-slot:icon>
                    Refresh
                </x-nawasara-ui::button>
            </div>
        @endif

        {{-- Stats Cards — pakai design-system stat-card untuk konsistensi
             dengan dashboard /home dan WHM Usage. Suspended pakai warning
             karena bukan critical, tapi perlu attention. --}}
        @php
            $suspended = $this->accountsCount['suspended'] ?? 0;
        @endphp
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <x-nawasara-ui::stat-card
                label="Total Accounts"
                :value="number_format($this->accountsCount['total'] ?? 0)"
                icon="lucide-users"
                color="primary"
                accent />

            <x-nawasara-ui::stat-card
                label="Active"
                :value="number_format($this->accountsCount['active'] ?? 0)"
                icon="lucide-circle-check"
                color="success"
                accent />

            <x-nawasara-ui::stat-card
                label="Suspended"
                :value="number_format($suspended)"
                icon="lucide-pause"
                :color="$suspended > 0 ? 'warning' : 'neutral'"
                accent />

            <x-nawasara-ui::stat-card
                label="WHM Version"
                :value="$this->status['version'] ?? '-'"
                icon="lucide-info"
                color="neutral"
                description="control panel"
                accent />
        </div>

        {{-- Load Average --}}
        @if (! empty($this->status['load']))
            <div class="bg-white dark:bg-neutral-800 border border-gray-200 dark:border-neutral-700 rounded-xl p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-neutral-300 mb-3">Load Average</h3>
                <div class="grid grid-cols-3 gap-4 text-center">
                    <div>
                        <p class="text-xs text-gray-500 dark:text-neutral-400">1 menit</p>
                        <p class="text-2xl font-bold text-gray-800 dark:text-neutral-200 font-mono">{{ $this->status['load']['one'] ?? '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-neutral-400">5 menit</p>
                        <p class="text-2xl font-bold text-gray-800 dark:text-neutral-200 font-mono">{{ $this->status['load']['five'] ?? '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-neutral-400">15 menit</p>
                        <p class="text-2xl font-bold text-gray-800 dark:text-neutral-200 font-mono">{{ $this->status['load']['fifteen'] ?? '-' }}</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Services Status --}}
        @if (! empty($this->status['services']))
            <div class="bg-white dark:bg-neutral-800 border border-gray-200 dark:border-neutral-700 rounded-xl p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-neutral-300 mb-3">Service Status</h3>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                    @foreach ($this->status['services'] as $service)
                        @php
                            $running = ($service['running'] ?? 0) == 1;
                        @endphp
                        <div class="flex items-center gap-2 p-3 rounded-lg bg-gray-50 dark:bg-neutral-700/50">
                            <div class="size-2 rounded-full {{ $running ? 'bg-green-500 text-green-500/40 animate-pulse-dot' : 'bg-red-500' }}"></div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800 dark:text-neutral-200 truncate">{{ $service['display_name'] ?? $service['name'] ?? '-' }}</p>
                                <p class="text-xs {{ $running ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    {{ $running ? 'Running' : 'Down' }}
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Disk Usage --}}
        @if (! empty($this->status['disk']))
            <div class="bg-white dark:bg-neutral-800 border border-gray-200 dark:border-neutral-700 rounded-xl p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-neutral-300 mb-3">Disk Usage</h3>
                <div class="space-y-3">
                    @foreach ($this->status['disk'] as $partition)
                        @php
                            $percent = (int) ($partition['percentage'] ?? 0);
                            $barColor = $percent > 90 ? 'bg-red-500' : ($percent > 75 ? 'bg-yellow-500' : 'bg-green-500');
                        @endphp
                        <div>
                            <div class="flex justify-between items-center text-xs mb-1">
                                <span class="font-mono text-gray-700 dark:text-neutral-300">{{ $partition['mount'] ?? '-' }}</span>
                                <span class="text-gray-500 dark:text-neutral-400">
                                    {{ $partition['used'] ?? '-' }} / {{ $partition['total'] ?? '-' }} ({{ $percent }}%)
                                </span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-neutral-700 rounded-full h-2">
                                <div class="{{ $barColor }} h-2 rounded-full transition-all" style="width: {{ $percent }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
