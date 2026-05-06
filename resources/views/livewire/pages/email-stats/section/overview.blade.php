<div wire:poll.60s wire:init="loadStats">
    @if (! count($this->servers))
        <div class="text-center py-12 border-2 border-dashed border-gray-200 dark:border-neutral-700 rounded-xl">
            <x-lucide-bar-chart-3 class="size-12 mx-auto text-gray-300 dark:text-neutral-600" />
            <p class="mt-3 text-sm text-gray-700 dark:text-neutral-300 font-medium">Belum ada server WHM dengan role mail</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-neutral-400">
                Tambahkan credential WHM di <a href="{{ url('nawasara-vault/credentials') }}" wire:navigate class="text-emerald-700 dark:text-emerald-400 hover:underline font-medium">Vault</a> dan set role = mail.
            </p>
        </div>
    @elseif (! $this->isConfigured)
        <div class="text-center py-12 border-2 border-dashed border-yellow-200 dark:border-yellow-900 rounded-xl bg-yellow-50/40 dark:bg-yellow-900/10">
            <x-lucide-key class="size-12 mx-auto text-yellow-400" />
            <p class="mt-3 text-sm text-gray-700 dark:text-neutral-300 font-medium">SSH belum dikonfigurasi untuk server ini</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-neutral-400">
                Email Stats membaca log Exim via SSH. Edit credential di Vault.
            </p>
        </div>
    @else
        {{-- Header bar --}}
        <div class="mb-4 flex items-center justify-between gap-3">
            <x-nawasara-whm::server-switcher :servers="$this->servers" role="mail" />
            <x-nawasara-ui::button color="neutral" variant="outline" size="sm" wire:click="refresh">
                <x-slot:icon>
                    <x-lucide-refresh-cw wire:loading.class="animate-spin" wire:target="refresh" />
                </x-slot:icon>
                Refresh
            </x-nawasara-ui::button>
        </div>

        @if (! $loaded)
            {{-- Skeleton: stats cards + trend chart + tiga panel bawah --}}
            <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-3">Hari Ini ({{ now()->format('d M Y') }})</h2>
            <x-nawasara-ui::skeleton-stats :cards="6" :cols="6" />
            <div class="my-6">
                <div class="p-5 rounded-xl border border-gray-200 dark:border-neutral-700 bg-white dark:bg-neutral-800">
                    <x-nawasara-ui::skeleton width="40" height="5" class="mb-4" />
                    <x-nawasara-ui::skeleton class="w-full" height="40" rounded="md" />
                </div>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                @for ($i = 0; $i < 3; $i++)
                    <div class="p-5 rounded-xl border border-gray-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 space-y-3">
                        <x-nawasara-ui::skeleton width="40" height="4" />
                        <x-nawasara-ui::skeleton class="w-full" height="3" />
                        <x-nawasara-ui::skeleton class="w-full" height="3" />
                        <x-nawasara-ui::skeleton class="w-full" height="3" />
                        <x-nawasara-ui::skeleton width="3/4" height="3" />
                    </div>
                @endfor
            </div>
        @else
        {{-- Today's stats cards — refactored to design-system stat-card.
             Color mapping ke design tokens:
             - Received: primary (informational, traffic count)
             - Delivered: success (positive outcome)
             - Bounced: danger (delivery failure)
             - Deferred: warning (transient delay, akan retry)
             - Spam: danger (security signal)
             - Queue: info (operational, lagi diproses) --}}
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-3">Hari Ini ({{ now()->format('d M Y') }})</h2>
        @php
            $cards = [
                ['label' => 'Received', 'value' => $this->todayCounts['received'], 'color' => 'primary', 'icon' => 'lucide-arrow-down-to-line'],
                ['label' => 'Delivered', 'value' => $this->todayCounts['delivered'], 'color' => 'success', 'icon' => 'lucide-circle-check'],
                ['label' => 'Bounced', 'value' => $this->todayCounts['bounced'], 'color' => 'danger', 'icon' => 'lucide-circle-x'],
                ['label' => 'Deferred', 'value' => $this->todayCounts['deferred'], 'color' => 'warning', 'icon' => 'lucide-clock'],
                ['label' => 'Spam', 'value' => $this->todayCounts['spam'], 'color' => 'danger', 'icon' => 'lucide-shield-x'],
                ['label' => 'Queue', 'value' => $this->queueSize, 'color' => 'info', 'icon' => 'lucide-inbox'],
            ];
        @endphp
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
            @foreach ($cards as $card)
                <x-nawasara-ui::stat-card
                    :label="$card['label']"
                    :value="number_format($card['value'])"
                    :icon="$card['icon']"
                    :color="$card['color']"
                    accent />
            @endforeach
        </div>

        {{-- Trend chart --}}
        <div class="mb-6 p-5 rounded-xl border border-gray-200 dark:border-neutral-700 bg-white dark:bg-neutral-800">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Trend ({{ $trendDays }} hari)</h3>
                <x-nawasara-ui::segmented-control
                    :options="['3' => '3d', '7' => '7d', '14' => '14d', '30' => '30d']"
                    :active="(string) $trendDays"
                    wire-method="setTrendDays"
                    size="sm" />
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
                                        ['key' => 'received', 'color' => 'bg-blue-500', 'label' => 'Received'],
                                        ['key' => 'delivered', 'color' => 'bg-green-500', 'label' => 'Delivered'],
                                        ['key' => 'deferred', 'color' => 'bg-yellow-500', 'label' => 'Deferred'],
                                        ['key' => 'bounced', 'color' => 'bg-red-500', 'label' => 'Bounced'],
                                    ];
                                @endphp
                                @foreach ($bars as $bar)
                                    @php $h = max(1, (int) round(($row[$bar['key']] / $max) * 100)); @endphp
                                    <div class="flex-1 {{ $bar['color'] }} rounded-t opacity-80 group-hover:opacity-100 transition"
                                        style="height: {{ $h }}%"
                                        title="{{ $bar['label'] }}: {{ $row[$bar['key']] }}"></div>
                                @endforeach
                            </div>
                            <div class="text-xs text-gray-500 dark:text-neutral-400 mt-1">
                                {{ \Carbon\Carbon::parse($row['date'])->format('d/m') }}
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 flex items-center justify-center gap-4 text-xs text-gray-500 dark:text-neutral-400">
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-sm bg-blue-500"></span> Received</span>
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-sm bg-green-500"></span> Delivered</span>
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-sm bg-yellow-500"></span> Deferred</span>
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-sm bg-red-500"></span> Bounced</span>
                </div>
            @endif
        </div>

        {{-- Hourly volume + Top senders/domains --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-1 p-5 rounded-xl border border-gray-200 dark:border-neutral-700 bg-white dark:bg-neutral-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">Volume per Jam (hari ini)</h3>
                <div class="flex items-end gap-px h-32">
                    @php $hMax = $this->hourlyMax; @endphp
                    @foreach ($this->hourly as $h)
                        @php $bh = max(2, (int) round(($h['count'] / $hMax) * 100)); @endphp
                        <div class="flex-1 bg-blue-500 rounded-t opacity-80 hover:opacity-100"
                            style="height: {{ $bh }}%"
                            title="{{ $h['hour'] }}:00 — {{ $h['count'] }} message"></div>
                    @endforeach
                </div>
                <div class="flex justify-between text-[10px] text-gray-400 dark:text-neutral-500 mt-1">
                    <span>00</span><span>06</span><span>12</span><span>18</span><span>23</span>
                </div>
            </div>

            <div class="p-5 rounded-xl border border-gray-200 dark:border-neutral-700 bg-white dark:bg-neutral-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">Top Senders Hari Ini</h3>
                @if (empty($this->topSenders))
                    <p class="text-sm text-gray-500 text-center py-4">Belum ada email diterima hari ini.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($this->topSenders as $s)
                            <li class="flex items-center justify-between gap-2 text-sm">
                                <span class="font-mono text-gray-700 dark:text-neutral-300 truncate" title="{{ $s['sender'] }}">{{ $s['sender'] }}</span>
                                <span class="font-bold text-gray-900 dark:text-white shrink-0">{{ $s['count'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="p-5 rounded-xl border border-gray-200 dark:border-neutral-700 bg-white dark:bg-neutral-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">Top Recipient Domain</h3>
                @if (empty($this->topDomains))
                    <p class="text-sm text-gray-500 text-center py-4">Belum ada email terkirim hari ini.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($this->topDomains as $d)
                            <li class="flex items-center justify-between gap-2 text-sm">
                                <span class="font-mono text-gray-700 dark:text-neutral-300 truncate" title="{{ $d['domain'] }}">{{ $d['domain'] }}</span>
                                <span class="font-bold text-gray-900 dark:text-white shrink-0">{{ $d['count'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
        @endif
    @endif
</div>
