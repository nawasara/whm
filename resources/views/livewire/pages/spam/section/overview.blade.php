<div wire:poll.60s>
    @if (! count($this->servers))
        <div class="text-center py-12 border-2 border-dashed border-gray-200 dark:border-neutral-700 rounded-xl">
            <x-lucide-shield-x class="size-12 mx-auto text-gray-300 dark:text-neutral-600" />
            <p class="mt-3 text-sm text-gray-700 dark:text-neutral-300 font-medium">Belum ada server WHM dengan role mail</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-neutral-400">
                Tambahkan credential WHM di <a href="{{ url('nawasara-vault/credentials') }}" wire:navigate class="text-blue-600 hover:underline">Vault</a> dan set role = mail.
            </p>
        </div>
    @elseif (! $this->isConfigured)
        <div class="text-center py-12 border-2 border-dashed border-yellow-200 dark:border-yellow-900 rounded-xl bg-yellow-50/40 dark:bg-yellow-900/10">
            <x-lucide-key class="size-12 mx-auto text-yellow-400" />
            <p class="mt-3 text-sm text-gray-700 dark:text-neutral-300 font-medium">SSH belum dikonfigurasi untuk server ini</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-neutral-400">
                Mail Security butuh akses SSH untuk baca exim_rejectlog. Edit credential di Vault.
            </p>
        </div>
    @else
        {{-- Header --}}
        <div class="mb-4 flex items-center justify-between gap-3">
            <x-nawasara-whm::server-switcher :servers="$this->servers" role="mail" />
            <x-nawasara-ui::button color="neutral" variant="outline" size="sm" wire:click="refresh">
                <x-slot:icon>
                    <x-lucide-refresh-cw wire:loading.class="animate-spin" wire:target="refresh" />
                </x-slot:icon>
                Refresh
            </x-nawasara-ui::button>
        </div>

        {{-- Today's stats cards --}}
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-3">Rejected Hari Ini ({{ now()->format('d M Y') }})</h2>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
            @php
                $cards = [
                    ['label' => 'Total', 'value' => $this->todayCounts['total'], 'color' => 'gray', 'icon' => 'lucide-shield-x'],
                    ['label' => 'Auth Fail', 'value' => $this->todayCounts['auth_fail'], 'color' => 'red', 'icon' => 'lucide-key-round'],
                    ['label' => 'RBL Block', 'value' => $this->todayCounts['rbl'], 'color' => 'purple', 'icon' => 'lucide-list-x'],
                    ['label' => 'Unknown User', 'value' => $this->todayCounts['unknown_user'], 'color' => 'yellow', 'icon' => 'lucide-user-x'],
                    ['label' => 'Spam', 'value' => $this->todayCounts['spam'], 'color' => 'orange', 'icon' => 'lucide-shield-alert'],
                    ['label' => 'Other', 'value' => $this->todayCounts['other'], 'color' => 'blue', 'icon' => 'lucide-circle-help'],
                ];
                $colorMap = [
                    'gray' => 'bg-gray-50 text-gray-700 dark:bg-neutral-800 dark:text-neutral-300 border-gray-200 dark:border-neutral-700',
                    'red' => 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400 border-red-200 dark:border-red-800',
                    'purple' => 'bg-purple-50 text-purple-700 dark:bg-purple-900/20 dark:text-purple-400 border-purple-200 dark:border-purple-800',
                    'yellow' => 'bg-yellow-50 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400 border-yellow-200 dark:border-yellow-800',
                    'orange' => 'bg-orange-50 text-orange-700 dark:bg-orange-900/20 dark:text-orange-400 border-orange-200 dark:border-orange-800',
                    'blue' => 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400 border-blue-200 dark:border-blue-800',
                ];
            @endphp
            @foreach ($cards as $card)
                <div class="p-4 rounded-xl border {{ $colorMap[$card['color']] }}">
                    <div class="flex items-center gap-2 text-xs uppercase tracking-wide opacity-80">
                        <x-dynamic-component :component="$card['icon']" class="size-4" />
                        {{ $card['label'] }}
                    </div>
                    <div class="mt-2 text-2xl font-bold">{{ number_format($card['value']) }}</div>
                </div>
            @endforeach
        </div>

        {{-- Trend chart --}}
        <div class="mb-6 p-5 rounded-xl border border-gray-200 dark:border-neutral-700 bg-white dark:bg-neutral-800">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Trend Reject ({{ $trendDays }} hari)</h3>
                <div class="flex items-center gap-1 text-xs">
                    @foreach ([3, 7, 14, 30] as $d)
                        <button wire:click="setTrendDays({{ $d }})"
                            class="px-3 py-1 rounded {{ $trendDays === $d
                                ? 'bg-blue-600 text-white'
                                : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-600' }}">
                            {{ $d }}d
                        </button>
                    @endforeach
                </div>
            </div>

            @if (empty($this->trend))
                <p class="text-sm text-gray-500 text-center py-8">Belum ada data trend.</p>
            @else
                <div class="flex items-end gap-2 h-48 mt-2">
                    @php $max = $this->trendMax; @endphp
                    @foreach ($this->trend as $row)
                        <div class="flex-1 flex flex-col items-center gap-0.5 group">
                            <div class="w-full flex items-end gap-px h-40">
                                @php
                                    $bars = [
                                        ['key' => 'auth_fail', 'color' => 'bg-red-500', 'label' => 'Auth Fail'],
                                        ['key' => 'rbl', 'color' => 'bg-purple-500', 'label' => 'RBL'],
                                        ['key' => 'unknown_user', 'color' => 'bg-yellow-500', 'label' => 'Unknown'],
                                        ['key' => 'spam', 'color' => 'bg-orange-500', 'label' => 'Spam'],
                                    ];
                                @endphp
                                @foreach ($bars as $bar)
                                    @php $h = max(1, (int) round(($row[$bar['key']] / $max) * 100)); @endphp
                                    <div class="flex-1 {{ $bar['color'] }} rounded-t opacity-80 group-hover:opacity-100 transition"
                                        style="height: {{ $h }}%"
                                        title="{{ $bar['label'] }}: {{ $row[$bar['key']] }} (total {{ $row['total'] }})"></div>
                                @endforeach
                            </div>
                            <div class="text-xs text-gray-500 dark:text-neutral-400 mt-1">
                                {{ \Carbon\Carbon::parse($row['date'])->format('d/m') }}
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 flex items-center justify-center gap-4 text-xs text-gray-500 dark:text-neutral-400">
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-sm bg-red-500"></span> Auth Fail</span>
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-sm bg-purple-500"></span> RBL</span>
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-sm bg-yellow-500"></span> Unknown User</span>
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-sm bg-orange-500"></span> Spam</span>
                </div>
            @endif
        </div>

        {{-- Top IP + Top targeted --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
            <div class="p-5 rounded-xl border border-gray-200 dark:border-neutral-700 bg-white dark:bg-neutral-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">Top Blocked IPs (hari ini)</h3>
                <p class="text-xs text-gray-500 dark:text-neutral-400 mb-3">
                    Source IP yang paling sering kena reject — calon kandidat untuk firewall block permanen.
                </p>
                @if (empty($this->topIps))
                    <p class="text-sm text-gray-500 text-center py-4">Belum ada reject hari ini.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($this->topIps as $ip)
                            <li class="flex items-center justify-between gap-2 text-sm">
                                <span class="font-mono text-gray-700 dark:text-neutral-300">{{ $ip['ip'] }}</span>
                                <span class="font-bold text-gray-900 dark:text-white shrink-0">{{ $ip['count'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="p-5 rounded-xl border border-gray-200 dark:border-neutral-700 bg-white dark:bg-neutral-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">Top Targeted Accounts (hari ini)</h3>
                <p class="text-xs text-gray-500 dark:text-neutral-400 mb-3">
                    Username yang paling sering jadi target — kalau angka tinggi banget, indikasi brute force ke akun itu.
                </p>
                @if (empty($this->topTargets))
                    <p class="text-sm text-gray-500 text-center py-4">Belum ada target attempt hari ini.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($this->topTargets as $t)
                            <li class="flex items-center justify-between gap-2 text-sm">
                                <span class="font-mono text-gray-700 dark:text-neutral-300 truncate" title="{{ $t['username'] }}">{{ $t['username'] }}</span>
                                <span class="font-bold {{ $t['count'] > 100 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white' }} shrink-0">{{ $t['count'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        {{-- Recent rejects table --}}
        <div class="p-5 rounded-xl border border-gray-200 dark:border-neutral-700 bg-white dark:bg-neutral-800">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">Reject Terbaru ({{ count($this->recent) }})</h3>
            @if (empty($this->recent))
                <p class="text-sm text-gray-500 text-center py-4">Belum ada reject.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs uppercase text-gray-500 dark:text-neutral-400 border-b border-gray-200 dark:border-neutral-700">
                            <tr>
                                <th class="text-left py-2 pr-3 font-semibold">Waktu</th>
                                <th class="text-left py-2 pr-3 font-semibold">Kategori</th>
                                <th class="text-left py-2 pr-3 font-semibold">IP</th>
                                <th class="text-left py-2 pr-3 font-semibold">Target</th>
                                <th class="text-left py-2 font-semibold">Detail</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-neutral-700">
                            @foreach ($this->recent as $r)
                                @php
                                    $catColor = match($r['category']) {
                                        'auth_fail' => 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                                        'rbl' => 'bg-purple-50 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
                                        'unknown_user' => 'bg-yellow-50 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
                                        'spam' => 'bg-orange-50 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
                                        default => 'bg-gray-100 text-gray-700 dark:bg-neutral-700 dark:text-neutral-300',
                                    };
                                    $catLabel = match($r['category']) {
                                        'auth_fail' => 'Auth Fail',
                                        'rbl' => 'RBL',
                                        'unknown_user' => 'Unknown',
                                        'spam' => 'Spam',
                                        default => 'Other',
                                    };
                                @endphp
                                <tr>
                                    <td class="py-2 pr-3 font-mono text-xs text-gray-500 dark:text-neutral-400 whitespace-nowrap">{{ $r['timestamp'] }}</td>
                                    <td class="py-2 pr-3">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $catColor }}">{{ $catLabel }}</span>
                                    </td>
                                    <td class="py-2 pr-3 font-mono text-xs text-gray-700 dark:text-neutral-300">{{ $r['ip'] ?? '-' }}</td>
                                    <td class="py-2 pr-3 font-mono text-xs text-gray-700 dark:text-neutral-300 truncate max-w-xs" title="{{ $r['user'] }}">{{ $r['user'] ?? '-' }}</td>
                                    <td class="py-2 text-xs text-gray-500 dark:text-neutral-400 truncate max-w-md" title="{{ $r['raw'] }}">{{ $r['summary'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</div>
