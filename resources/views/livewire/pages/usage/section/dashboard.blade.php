<div class="space-y-6">
    @if (! count($this->servers))
        <div class="text-center py-12 border-2 border-dashed border-gray-200 dark:border-neutral-700 rounded-xl">
            <x-lucide-server class="size-12 mx-auto text-gray-300 dark:text-neutral-600" />
            <p class="mt-3 text-sm text-gray-700 dark:text-neutral-300 font-medium">Belum ada server WHM dikonfigurasi</p>
        </div>
    @else
        <x-nawasara-whm::server-switcher :servers="$this->servers" role="hosting" />

        {{-- Summary cards (clickable for filter) --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <button type="button" wire:click="setStateFilter('')"
                class="text-left bg-white dark:bg-neutral-800 border {{ $stateFilter === '' ? 'border-blue-500 ring-2 ring-blue-100 dark:ring-blue-900/30' : 'border-gray-200 dark:border-neutral-700' }} rounded-xl p-5 hover:shadow-md transition-all">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                        <x-lucide-users class="size-5" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-neutral-400">Total Accounts</p>
                        <p class="text-2xl font-bold text-gray-800 dark:text-neutral-200">{{ $this->summary['total'] }}</p>
                    </div>
                </div>
            </button>
            <button type="button" wire:click="setStateFilter('ok')"
                class="text-left bg-white dark:bg-neutral-800 border {{ $stateFilter === 'ok' ? 'border-green-500 ring-2 ring-green-100 dark:ring-green-900/30' : 'border-gray-200 dark:border-neutral-700' }} rounded-xl p-5 hover:shadow-md transition-all">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-lg bg-green-50 text-green-600 dark:bg-green-900/30 dark:text-green-400">
                        <x-lucide-check-circle class="size-5" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-neutral-400">Healthy (&lt; 80%)</p>
                        <p class="text-2xl font-bold text-gray-800 dark:text-neutral-200">{{ $this->summary['ok'] }}</p>
                    </div>
                </div>
            </button>
            <button type="button" wire:click="setStateFilter('warning')"
                class="text-left bg-white dark:bg-neutral-800 border {{ $stateFilter === 'warning' ? 'border-yellow-500 ring-2 ring-yellow-100 dark:ring-yellow-900/30' : 'border-gray-200 dark:border-neutral-700' }} rounded-xl p-5 hover:shadow-md transition-all">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-lg bg-yellow-50 text-yellow-600 dark:bg-yellow-900/30 dark:text-yellow-400">
                        <x-lucide-alert-triangle class="size-5" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-neutral-400">Warning (&gt;= 80%)</p>
                        <p class="text-2xl font-bold text-gray-800 dark:text-neutral-200">{{ $this->summary['warning'] }}</p>
                    </div>
                </div>
            </button>
            <button type="button" wire:click="setStateFilter('critical')"
                class="text-left bg-white dark:bg-neutral-800 border {{ $stateFilter === 'critical' ? 'border-red-500 ring-2 ring-red-100 dark:ring-red-900/30' : 'border-gray-200 dark:border-neutral-700' }} rounded-xl p-5 hover:shadow-md transition-all">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-lg bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400">
                        <x-lucide-alert-circle class="size-5" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-neutral-400">Critical (&gt;= 95%)</p>
                        <p class="text-2xl font-bold text-gray-800 dark:text-neutral-200">{{ $this->summary['critical'] }}</p>
                    </div>
                </div>
            </button>
        </div>

        {{-- Usage Table --}}
        <x-nawasara-ui::table
            :headers="['User', 'Domain', 'Package', 'Disk', 'Bandwidth', 'Status']"
            :title="'Resource Usage' . ($stateFilter ? ' (filter: '.ucfirst($stateFilter).')' : '')">
            <x-slot:table>
                @forelse ($this->usageList as $row)
                    @php
                        $stateBadge = match($row['state']) {
                            'ok' => 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                            'warning' => 'bg-yellow-50 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
                            'critical' => 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                            default => 'bg-gray-100 text-gray-700 dark:bg-neutral-700 dark:text-neutral-400',
                        };
                        $diskBarColor = $row['disk_pct'] >= 95 ? 'bg-red-500' : ($row['disk_pct'] >= 80 ? 'bg-yellow-500' : 'bg-green-500');
                        $bwBarColor = $row['bw_pct'] >= 95 ? 'bg-red-500' : ($row['bw_pct'] >= 80 ? 'bg-yellow-500' : 'bg-green-500');
                    @endphp
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-neutral-200 font-mono">
                            {{ $row['user'] }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-neutral-300">
                            {{ $row['domain'] }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-50 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400">
                                {{ $row['plan'] }}
                            </span>
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
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $stateBadge }}">
                                {{ ucfirst($row['state']) }}
                            </span>
                            @if ($row['suspended'])
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-neutral-700 dark:text-neutral-400 ml-1">
                                    Suspended
                                </span>
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
