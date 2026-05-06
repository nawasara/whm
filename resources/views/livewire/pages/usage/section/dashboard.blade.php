<div class="space-y-6">
    @if (! count($this->servers))
        {{-- Empty state — premium look mirip dashboard /home --}}
        <div class="text-center py-16 px-6 border-2 border-dashed border-gray-200 dark:border-neutral-700 rounded-xl bg-gray-50/50 dark:bg-neutral-900/40">
            <div class="inline-flex items-center justify-center size-14 rounded-2xl bg-gray-100 dark:bg-neutral-800 mb-4">
                <x-lucide-server class="size-7 text-gray-400 dark:text-neutral-500" />
            </div>
            <p class="text-base font-semibold text-gray-800 dark:text-neutral-200">
                Belum ada server WHM dikonfigurasi
            </p>
            <p class="mt-2 text-sm text-gray-500 dark:text-neutral-400 max-w-sm mx-auto">
                Tambahkan WHM server di Settings untuk mulai monitoring resource usage akun hosting.
            </p>
        </div>
    @else
        <x-nawasara-whm::server-switcher :servers="$this->servers" role="hosting" />

        {{-- Summary cards (clickable for filter).
             Accent border-left + active ring kasih dual visual feedback:
             accent = brand consistency, active = mana yang sedang dipilih. --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <x-nawasara-ui::stat-card
                label="Total Accounts"
                :value="$this->summary['total']"
                icon="lucide-users"
                color="primary"
                :active="$stateFilter === ''"
                accent
                wire:click="setStateFilter('')" />

            <x-nawasara-ui::stat-card
                label="Healthy"
                :value="$this->summary['ok']"
                icon="lucide-circle-check"
                color="success"
                :active="$stateFilter === 'ok'"
                accent
                description="< 80% utilization"
                wire:click="setStateFilter('ok')" />

            <x-nawasara-ui::stat-card
                label="Warning"
                :value="$this->summary['warning']"
                icon="lucide-triangle-alert"
                color="warning"
                :active="$stateFilter === 'warning'"
                accent
                description=">= 80% utilization"
                wire:click="setStateFilter('warning')" />

            <x-nawasara-ui::stat-card
                label="Critical"
                :value="$this->summary['critical']"
                icon="lucide-circle-alert"
                color="danger"
                :active="$stateFilter === 'critical'"
                accent
                description=">= 95% — perlu action"
                wire:click="setStateFilter('critical')" />
        </div>

        {{-- Usage Table --}}
        <x-nawasara-ui::table
            :headers="['User', 'Domain', 'Package', 'Disk', 'Bandwidth', 'Status']"
            :title="'Resource Usage' . ($stateFilter ? ' (filter: '.ucfirst($stateFilter).')' : '')">
            <x-slot:table>
                @forelse ($this->usageList as $row)
                    @php
                        $stateColor = match ($row['state']) {
                            'ok' => 'success',
                            'warning' => 'warning',
                            'critical' => 'danger',
                            default => 'neutral',
                        };
                        // Bar color tetap inline literal — itu visualisasi data, bukan
                        // status indicator, jadi tidak masuk akal di-componentize.
                        $diskBarColor = $row['disk_pct'] >= 95 ? 'bg-rose-500' : ($row['disk_pct'] >= 80 ? 'bg-amber-500' : 'bg-green-500');
                        $bwBarColor = $row['bw_pct'] >= 95 ? 'bg-rose-500' : ($row['bw_pct'] >= 80 ? 'bg-amber-500' : 'bg-green-500');
                    @endphp
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-neutral-200 font-mono">
                            {{ $row['user'] }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-neutral-300">
                            {{ $row['domain'] }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <x-nawasara-ui::badge color="info" variant="soft">
                                {{ $row['plan'] }}
                            </x-nawasara-ui::badge>
                        </td>
                        <td class="px-6 py-4 min-w-[180px]">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="text-gray-600 dark:text-neutral-400 font-mono">{{ $row['disk_used'] }} / {{ $row['disk_limit'] }}</span>
                                <span class="text-gray-500 dark:text-neutral-400">{{ $row['disk_pct'] }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-neutral-700 rounded-full h-1.5">
                                <div class="{{ $diskBarColor }} h-1.5 rounded-full" style="width: {{ min(100, $row['disk_pct']) }}%"></div>
                            </div>
                        </td>
                        <td class="px-6 py-4 min-w-[180px]">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="text-gray-600 dark:text-neutral-400 font-mono">{{ $row['bw_used'] }} / {{ $row['bw_limit'] }}</span>
                                <span class="text-gray-500 dark:text-neutral-400">{{ $row['bw_pct'] }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-neutral-700 rounded-full h-1.5">
                                <div class="{{ $bwBarColor }} h-1.5 rounded-full" style="width: {{ min(100, $row['bw_pct']) }}%"></div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <x-nawasara-ui::badge :color="$stateColor" dot>
                                {{ ucfirst($row['state']) }}
                            </x-nawasara-ui::badge>
                            @if ($row['suspended'])
                                <x-nawasara-ui::badge color="neutral" class="ml-1">
                                    Suspended
                                </x-nawasara-ui::badge>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500 dark:text-neutral-400">
                            Tidak ada akun dengan status ini.
                        </td>
                    </tr>
                @endforelse
            </x-slot:table>
        </x-nawasara-ui::table>
    @endif
</div>
