<div wire:init="loadQueue">
    @if (! count($this->servers))
        <div class="text-center py-12 border-2 border-dashed border-gray-200 dark:border-neutral-700 rounded-xl">
            <x-lucide-inbox class="size-12 mx-auto text-gray-300 dark:text-neutral-600" />
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
                Mail Queue butuh akses SSH ke server. Edit credential di
                <a href="{{ url('nawasara-vault/credentials') }}" wire:navigate class="text-blue-600 hover:underline">Vault</a>
                dan isi field SSH (user, port, private key).
            </p>
        </div>
    @elseif (! $loaded)
        <div class="mb-3 flex items-center gap-3">
            <x-nawasara-ui::skeleton width="40" height="3" />
            <x-nawasara-ui::skeleton width="20" height="3" />
            <x-nawasara-ui::skeleton width="20" height="3" />
        </div>
        <div class="mb-3 flex items-center gap-2">
            <x-nawasara-ui::skeleton width="48" height="9" rounded="lg" />
            <x-nawasara-ui::skeleton width="32" height="9" rounded="lg" />
            <x-nawasara-ui::skeleton width="32" height="9" rounded="lg" />
        </div>
        <x-nawasara-ui::skeleton-table :rows="5" :cols="6" />
    @else
        {{-- Status summary bar --}}
        <div class="mb-3 flex items-center justify-between text-xs text-gray-500 dark:text-neutral-400">
            <div class="flex items-center gap-3">
                <span><strong class="text-gray-700 dark:text-neutral-300">{{ count($this->allItems) }}</strong> total dalam queue</span>
                @if ($this->statusCounts['queued'] > 0)
                    <span class="text-blue-600">{{ $this->statusCounts['queued'] }} queued</span>
                @endif
                @if ($this->statusCounts['deferred'] > 0)
                    <span class="text-yellow-600">{{ $this->statusCounts['deferred'] }} deferred</span>
                @endif
                @if ($this->statusCounts['frozen'] > 0)
                    <span class="text-red-600">{{ $this->statusCounts['frozen'] }} frozen</span>
                @endif
            </div>
        </div>

        <x-nawasara-ui::filter-bar searchPlaceholder="Cari id, sender, recipient..." searchModel="search">
            <x-nawasara-whm::server-switcher :servers="$this->servers" role="mail" />

            <x-nawasara-ui::filter-dropdown label="Status" model="statusFilter"
                :items="['all' => 'Semua Status', 'queued' => 'Queued', 'deferred' => 'Deferred', 'frozen' => 'Frozen']" />

            <x-nawasara-ui::filter-dropdown label="Age" model="ageFilter"
                :items="['all' => 'Semua Age', '1h' => '> 1 jam', '24h' => '> 24 jam', '7d' => '> 7 hari']" />

            <x-slot:actions>
                <x-nawasara-ui::button color="neutral" variant="outline" size="sm" wire:click="refresh">
                    <x-slot:icon>
                        <x-lucide-refresh-cw wire:loading.class="animate-spin" wire:target="refresh" />
                    </x-slot:icon>
                    Refresh
                </x-nawasara-ui::button>

                @can('whm.mailqueue.manage')
                    <x-nawasara-ui::button color="warning" variant="outline" size="sm" wire:click="flushAll" wire:confirm="Flush seluruh queue? Exim akan coba kirim semua message sekarang.">
                        <x-slot:icon><x-lucide-zap /></x-slot:icon>
                        Flush All
                    </x-nawasara-ui::button>
                @endcan
            </x-slot:actions>

            <x-slot:chips>
                @if ($statusFilter)
                    <x-nawasara-ui::filter-chip label="Status: {{ ucfirst($statusFilter) }}" model="statusFilter" />
                @endif
                @if ($ageFilter)
                    <x-nawasara-ui::filter-chip label="Age: {{ $ageFilter }}" model="ageFilter" />
                @endif
                @if ($search)
                    <x-nawasara-ui::filter-chip label="Cari: {{ $search }}" model="search" />
                @endif
            </x-slot:chips>
        </x-nawasara-ui::filter-bar>

        @can('whm.mailqueue.manage')
            <x-nawasara-ui::bulk-action-bar :count="count($selected)" clearAction="resetSelection" label="message dipilih">
                <x-nawasara-ui::button color="primary" variant="outline" size="sm" wire:click="bulkForce">
                    <x-slot:icon><x-lucide-send /></x-slot:icon>
                    Force Delivery
                </x-nawasara-ui::button>
                <x-nawasara-ui::button color="warning" variant="outline" size="sm" wire:click="bulkFreeze">
                    <x-slot:icon><x-lucide-snowflake /></x-slot:icon>
                    Freeze
                </x-nawasara-ui::button>
                <x-nawasara-ui::button color="danger" variant="outline" size="sm" wire:click="bulkDelete" wire:confirm="HAPUS {{ count($selected) }} message dari queue?">
                    <x-slot:icon><x-lucide-trash-2 /></x-slot:icon>
                    Delete
                </x-nawasara-ui::button>
            </x-nawasara-ui::bulk-action-bar>
        @endcan

        @php
            $selectAllHeader = '<input type="checkbox" wire:model.live="selectAll" class="size-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:bg-neutral-800 dark:border-neutral-600">';
        @endphp
        <x-nawasara-ui::table
            :headers="[$selectAllHeader, 'ID', 'Status', 'Age', 'Size', 'Sender', 'Recipients', '']"
            :title="'Queue Items ('.count($this->items).' shown)'">
            <x-slot:table>
                @forelse ($this->pagedItems as $item)
                    <tr wire:key="qi-{{ $item['id'] }}" class="{{ in_array($item['id'], $selected) ? 'bg-blue-50/50 dark:bg-blue-900/10' : '' }}">
                        <td class="px-6 py-3 whitespace-nowrap">
                            <input type="checkbox" wire:model.live="selected" value="{{ $item['id'] }}"
                                class="size-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:bg-neutral-800 dark:border-neutral-600">
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap text-xs font-mono text-gray-700 dark:text-neutral-300">
                            {{ $item['id'] }}
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap text-sm">
                            @if ($item['status'] === 'frozen')
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                    <x-lucide-snowflake class="size-3" /> Frozen
                                </span>
                            @elseif ($item['status'] === 'deferred')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-50 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">
                                    Deferred
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                                    Queued
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-neutral-400 font-mono">
                            {{ $item['age'] }}
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-neutral-400 font-mono">
                            {{ $item['size'] }}
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-700 dark:text-neutral-300 font-mono max-w-xs truncate">
                            {{ $item['sender'] ?? '<>' }}
                        </td>
                        <td class="px-6 py-3 text-sm text-gray-700 dark:text-neutral-300 font-mono">
                            @if (count($item['recipients']) <= 1)
                                {{ $item['recipients'][0] ?? '-' }}
                            @else
                                <span title="{{ implode(', ', $item['recipients']) }}">
                                    {{ $item['recipients'][0] }} <span class="text-gray-400">+{{ count($item['recipients']) - 1 }}</span>
                                </span>
                            @endif
                            @if ($item['last_error'])
                                <div class="text-xs text-red-600 dark:text-red-400 mt-0.5 truncate max-w-md" title="{{ $item['last_error'] }}">
                                    {{ \Illuminate\Support\Str::limit($item['last_error'], 80) }}
                                </div>
                            @elseif ($item['age_seconds'] > 86400 && $item['status'] !== 'frozen')
                                <button type="button" wire:click="openDetail('{{ $item['id'] }}')"
                                    class="text-xs text-yellow-600 dark:text-yellow-400 mt-0.5 hover:underline inline-flex items-center gap-1">
                                    <x-lucide-alert-circle class="size-3" />
                                    Stuck >24h — lihat delivery log
                                </button>
                            @endif
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap text-sm text-right">
                            <x-nawasara-ui::dropdown-menu-action :id="$item['id']" :items="[
                                ['type' => 'click', 'label' => 'Detail', 'wire:click' => 'openDetail(\''.$item['id'].'\')', 'modal' => 'whm-mailqueue-detail', 'icon' => 'lucide-eye', 'permission' => 'whm.mailqueue.view'],
                                ['type' => 'click', 'label' => 'Force Delivery', 'wire:click' => 'forceOne(\''.$item['id'].'\')', 'icon' => 'lucide-send', 'permission' => 'whm.mailqueue.manage'],
                                $item['status'] === 'frozen'
                                    ? ['type' => 'click', 'label' => 'Thaw', 'wire:click' => 'thawOne(\''.$item['id'].'\')', 'icon' => 'lucide-sun', 'permission' => 'whm.mailqueue.manage']
                                    : ['type' => 'click', 'label' => 'Freeze', 'wire:click' => 'freezeOne(\''.$item['id'].'\')', 'icon' => 'lucide-snowflake', 'permission' => 'whm.mailqueue.manage'],
                                ['type' => 'click', 'label' => 'Bounce ke Sender', 'wire:click' => 'bounceOne(\''.$item['id'].'\')', 'icon' => 'lucide-undo-2', 'confirm' => 'Bounce message? Sender akan dapat notice delivery failed.', 'permission' => 'whm.mailqueue.manage'],
                                ['type' => 'click', 'label' => 'Delete', 'wire:click' => 'deleteOne(\''.$item['id'].'\')', 'icon' => 'lucide-trash-2', 'confirm' => 'Hapus message ini dari queue?', 'permission' => 'whm.mailqueue.manage'],
                            ]" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-6 py-8 text-center text-sm text-gray-500 dark:text-neutral-400">
                            @if (count($this->allItems) === 0)
                                <x-lucide-inbox class="size-8 mx-auto text-gray-300 mb-2" />
                                Mail queue kosong — tidak ada message tertunda.
                            @else
                                Tidak ada message yang cocok filter ini.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </x-slot:table>

            <x-slot:footer>
                @if ($this->totalPages > 1)
                    <div class="flex items-center justify-between px-4 py-3 text-sm text-gray-500 dark:text-neutral-400">
                        <span>Halaman {{ $page }} dari {{ $this->totalPages }}</span>
                        <div class="flex items-center gap-2">
                            <x-nawasara-ui::button color="neutral" variant="outline" size="sm" wire:click="prevPage" :disabled="$page <= 1">
                                Sebelumnya
                            </x-nawasara-ui::button>
                            <x-nawasara-ui::button color="neutral" variant="outline" size="sm" wire:click="nextPage" :disabled="$page >= $this->totalPages">
                                Berikutnya
                            </x-nawasara-ui::button>
                        </div>
                    </div>
                @endif
            </x-slot:footer>
        </x-nawasara-ui::table>
    @endif

    {{-- Detail Modal --}}
    <x-nawasara-ui::modal id="whm-mailqueue-detail" maxWidth="3xl" :title="'Message: '.($detailId ?? '')">
        @if ($detailId)
            {{-- Tab nav --}}
            <div class="border-b border-gray-200 dark:border-neutral-700 mb-3">
                <nav class="flex -mb-px gap-4 text-sm" aria-label="Tabs">
                    <button type="button" wire:click="setDetailTab('log')"
                        class="py-2 px-1 border-b-2 font-medium {{ $detailTab === 'log' ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-neutral-400 dark:hover:text-neutral-300' }}">
                        <x-lucide-activity class="size-4 inline -mt-0.5" /> Delivery Log
                    </button>
                    <button type="button" wire:click="setDetailTab('headers')"
                        class="py-2 px-1 border-b-2 font-medium {{ $detailTab === 'headers' ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-neutral-400 dark:hover:text-neutral-300' }}">
                        <x-lucide-mail class="size-4 inline -mt-0.5" /> Headers
                    </button>
                    <button type="button" wire:click="setDetailTab('body')"
                        class="py-2 px-1 border-b-2 font-medium {{ $detailTab === 'body' ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-neutral-400 dark:hover:text-neutral-300' }}">
                        <x-lucide-file-text class="size-4 inline -mt-0.5" /> Body Preview
                    </button>
                </nav>
            </div>

            <div class="text-sm">
                @if ($detailTab === 'log')
                    <p class="text-xs text-gray-500 dark:text-neutral-400 mb-2">
                        Setiap delivery attempt yang Exim sudah lakukan untuk message ini, termasuk SMTP response dari remote server.
                        Cara terbaik melihat <em>kenapa</em> message stuck.
                    </p>
                    <pre class="text-xs bg-gray-50 dark:bg-neutral-900 p-3 rounded border border-gray-200 dark:border-neutral-700 overflow-x-auto whitespace-pre-wrap font-mono text-gray-800 dark:text-neutral-200 max-h-[28rem]">{{ trim($detailLog ?? '') ?: '(belum ada delivery attempt yang ter-log)' }}</pre>
                @elseif ($detailTab === 'headers')
                    <pre class="text-xs bg-gray-50 dark:bg-neutral-900 p-3 rounded border border-gray-200 dark:border-neutral-700 overflow-x-auto whitespace-pre-wrap font-mono text-gray-800 dark:text-neutral-200 max-h-[28rem]">{{ $detailHeaders ?? '(empty)' }}</pre>
                @else
                    <p class="text-xs text-gray-500 dark:text-neutral-400 mb-2">Body preview (maksimal 100 baris pertama).</p>
                    <pre class="text-xs bg-gray-50 dark:bg-neutral-900 p-3 rounded border border-gray-200 dark:border-neutral-700 overflow-x-auto whitespace-pre-wrap font-mono text-gray-800 dark:text-neutral-200 max-h-[28rem]">{{ $detailBody ?? '(empty)' }}</pre>
                @endif
            </div>
        @endif
        <x-slot:footer>
            @can('whm.mailqueue.manage')
                @if ($detailId)
                    <x-nawasara-ui::button color="warning" variant="outline" wire:click="bounceOne('{{ $detailId }}')" wire:confirm="Bounce message? Sender akan dapat notice delivery failed.">
                        <x-slot:icon><x-lucide-undo-2 /></x-slot:icon>
                        Bounce ke Sender
                    </x-nawasara-ui::button>
                @endif
            @endcan
            <x-nawasara-ui::button color="neutral" variant="outline" wire:click="closeDetail">Tutup</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>
</div>
