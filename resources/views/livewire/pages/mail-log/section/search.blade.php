<div>
    @if (! count($this->servers))
        <div class="text-center py-12 border-2 border-dashed border-gray-200 dark:border-neutral-700 rounded-xl">
            <x-lucide-scroll-text class="size-12 mx-auto text-gray-300 dark:text-neutral-600" />
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
                Mail Log search butuh akses SSH. Edit credential di
                <a href="{{ url('nawasara-vault/credentials') }}" wire:navigate class="text-blue-600 hover:underline">Vault</a>
                dan isi field SSH.
            </p>
        </div>
    @else
        {{-- Search form card --}}
        <form wire:submit.prevent="search" class="mb-4 p-4 rounded-xl border border-gray-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold text-gray-700 dark:text-neutral-300">Filter Pencarian</h3>
                <x-nawasara-whm::server-switcher :servers="$this->servers" role="mail" />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div>
                    <x-nawasara-ui::form.label value="Dari Tanggal" />
                    <x-nawasara-ui::form.input type="date" wire:model="dateFrom" />
                </div>
                <div>
                    <x-nawasara-ui::form.label value="Sampai Tanggal" />
                    <x-nawasara-ui::form.input type="date" wire:model="dateTo" />
                </div>
                <div class="md:col-span-2">
                    <x-nawasara-ui::form.label value="Quick Range" />
                    {{-- setQuickRange() tidak menyimpan key sebagai state — dia
                         translate ke dateFrom langsung. Jadi segmented-control
                         tidak punya "active" feedback (intentional: quick-shortcut
                         behavior, bukan persistent filter). --}}
                    <x-nawasara-ui::segmented-control
                        :options="['today' => 'Hari ini', '24h' => '24 jam', '7d' => '7 hari', '30d' => '30 hari']"
                        wire-method="setQuickRange"
                        size="sm" />
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <x-nawasara-ui::form.label value="Sender (contains)" />
                    <x-nawasara-ui::form.input wire:model="sender" placeholder="dinkes@" />
                </div>
                <div>
                    <x-nawasara-ui::form.label value="Recipient (contains)" />
                    <x-nawasara-ui::form.input wire:model="recipient" placeholder="@gmail.com" />
                </div>
                <div>
                    <x-nawasara-ui::form.label value="Message ID" />
                    <x-nawasara-ui::form.input wire:model="messageId" placeholder="1qABCd-..." />
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                <div>
                    <x-nawasara-ui::form.label value="Status" />
                    <x-nawasara-ui::form.select wire:model="status" :placeholder="false">
                        <option value="">Semua Status</option>
                        <option value="received">Received (in)</option>
                        <option value="delivered">Delivered (out)</option>
                        <option value="bounced">Bounced</option>
                        <option value="deferred">Deferred</option>
                    </x-nawasara-ui::form.select>
                </div>
                <div>
                    <x-nawasara-ui::form.label value="Limit Hasil (max 5000)" />
                    <x-nawasara-ui::form.input type="number" wire:model="limit" min="10" max="5000" />
                    <label class="flex items-center gap-2 mt-2 text-xs text-gray-600 dark:text-neutral-400 cursor-pointer">
                        <input type="checkbox" wire:model="hideNoise" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:bg-neutral-800 dark:border-neutral-600">
                        Sembunyikan noise (event tanpa msg id: connection log, auth fail)
                    </label>
                </div>
                <div class="flex items-end gap-2">
                    <x-nawasara-ui::button type="submit" color="primary">
                        <x-slot:icon>
                            <x-lucide-search wire:loading.class="hidden" wire:target="search" />
                            <x-lucide-loader-2 wire:loading wire:target="search" class="animate-spin" />
                        </x-slot:icon>
                        Cari
                    </x-nawasara-ui::button>
                    <x-nawasara-ui::button type="button" color="neutral" variant="outline" wire:click="resetForm">
                        Reset
                    </x-nawasara-ui::button>
                </div>
            </div>
        </form>

        {{-- Result summary --}}
        @if ($hasSearched && ! $errorMessage)
            <div class="mb-3 flex items-center justify-between text-xs text-gray-500 dark:text-neutral-400">
                <span>
                    <strong class="text-gray-700 dark:text-neutral-300">{{ count($results) }}</strong> hasil
                    @if ($elapsedMs !== null)
                        dalam {{ $elapsedMs }} ms
                    @endif
                    (newest first)
                </span>
                <span class="text-gray-400">Klik baris untuk trace full event chain</span>
            </div>
        @elseif ($errorMessage)
            <div class="mb-3 p-3 rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-800 text-sm text-red-700 dark:text-red-300">
                <x-lucide-alert-circle class="size-4 inline -mt-0.5" /> {{ $errorMessage }}
            </div>
        @endif

        {{-- Results --}}
        @if ($hasSearched && ! empty($results))
            <x-nawasara-ui::table
                :headers="['Waktu', 'Status', 'Message ID', 'Address', 'Info']"
                title="Mail Log Results">
                <x-slot:table>
                    @foreach ($results as $entry)
                        <tr wire:key="log-{{ $loop->index }}">
                            <td class="px-6 py-2 whitespace-nowrap text-xs text-gray-500 dark:text-neutral-400 font-mono">
                                {{ $entry['raw_timestamp'] }}
                            </td>
                            <td class="px-6 py-2 whitespace-nowrap text-sm">
                                @php
                                    $statusColor = match ($entry['status']) {
                                        'received' => 'primary',
                                        'delivered', 'completed' => 'success',
                                        'bounced' => 'danger',
                                        'deferred' => 'warning',
                                        default => 'neutral',
                                    };
                                @endphp
                                <x-nawasara-ui::badge :color="$statusColor">
                                    {{ ucfirst($entry['status']) }}
                                </x-nawasara-ui::badge>
                            </td>
                            <td class="px-6 py-2 whitespace-nowrap text-xs font-mono">
                                @if ($entry['message_id'])
                                    <x-nawasara-ui::button variant="link" color="primary" size="sm"
                                        wire:click="openTrace('{{ $entry['message_id'] }}')"
                                        class="text-xs font-mono">
                                        {{ $entry['message_id'] }}
                                    </x-nawasara-ui::button>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </td>
                            <td class="px-6 py-2 text-xs font-mono text-gray-700 dark:text-neutral-300 max-w-xs truncate" title="{{ $entry['address'] }}">
                                {{ $entry['address'] ?? '-' }}
                            </td>
                            <td class="px-6 py-2 text-xs text-gray-500 dark:text-neutral-400 max-w-md truncate" title="{{ $entry['info'] }}">
                                {{ \Illuminate\Support\Str::limit($entry['info'], 100) }}
                            </td>
                        </tr>
                    @endforeach
                </x-slot:table>
            </x-nawasara-ui::table>
        @elseif ($hasSearched && empty($results) && ! $errorMessage)
            <div class="text-center py-12 border-2 border-dashed border-gray-200 dark:border-neutral-700 rounded-xl">
                <x-lucide-file-search class="size-12 mx-auto text-gray-300 dark:text-neutral-600" />
                <p class="mt-3 text-sm text-gray-500 dark:text-neutral-400">Tidak ada log entry yang cocok filter ini.</p>
            </div>
        @elseif (! $hasSearched)
            <div class="text-center py-12 border-2 border-dashed border-gray-200 dark:border-neutral-700 rounded-xl">
                <x-lucide-search class="size-12 mx-auto text-gray-300 dark:text-neutral-600" />
                <p class="mt-3 text-sm text-gray-500 dark:text-neutral-400">Isi filter di atas dan klik <strong>Cari</strong>.</p>
            </div>
        @endif
    @endif

    {{-- Trace Modal --}}
    <x-nawasara-ui::modal id="whm-maillog-trace" maxWidth="3xl" :title="'Trace: '.($traceId ?? '')">
        @if ($traceId)
            <p class="text-xs text-gray-500 dark:text-neutral-400 mb-3">
                Semua event di mainlog yang menyebut message id ini, urut dari paling lama.
            </p>
            @if (empty($traceEvents))
                <p class="text-sm text-gray-500">Tidak ditemukan event di log untuk message id ini.</p>
            @else
                <div class="space-y-1.5 max-h-[28rem] overflow-y-auto">
                    @foreach ($traceEvents as $ev)
                        @php
                            $color = match ($ev['status']) {
                                'received' => 'border-blue-300 bg-blue-50 dark:bg-blue-900/20 dark:border-blue-800',
                                'delivered', 'completed' => 'border-green-300 bg-green-50 dark:bg-green-900/20 dark:border-green-800',
                                'bounced' => 'border-red-300 bg-red-50 dark:bg-red-900/20 dark:border-red-800',
                                'deferred' => 'border-yellow-300 bg-yellow-50 dark:bg-yellow-900/20 dark:border-yellow-800',
                                default => 'border-gray-200 bg-gray-50 dark:bg-neutral-700/30 dark:border-neutral-700',
                            };
                        @endphp
                        <div class="border-l-4 {{ $color }} px-3 py-2 rounded-r text-xs">
                            <div class="flex items-center justify-between gap-2 mb-0.5">
                                <span class="font-mono text-gray-500 dark:text-neutral-400">{{ $ev['raw_timestamp'] }}</span>
                                <span class="font-medium uppercase tracking-wide text-gray-700 dark:text-neutral-300">{{ $ev['status'] }}</span>
                            </div>
                            @if ($ev['address'])
                                <div class="font-mono text-gray-700 dark:text-neutral-300">{{ $ev['address'] }}</div>
                            @endif
                            @if ($ev['info'])
                                <div class="font-mono text-gray-500 dark:text-neutral-400 mt-0.5 break-all">{{ $ev['info'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" wire:click="closeTrace">Tutup</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>
</div>
