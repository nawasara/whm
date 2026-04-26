@props([
    'servers' => [],          // list of instance names
    'model' => 'server',      // wire:model target
    'role' => null,           // optional: 'hosting' or 'mail' for context
])

@php
    /** @var \Nawasara\Whm\Services\WhmClient $whm */
    $whm = app(\Nawasara\Whm\Services\WhmClient::class);
    $hasMultiple = count($servers) > 1;
@endphp

@if (count($servers) === 0)
    <div class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-yellow-200 bg-yellow-50 text-xs text-yellow-700 dark:bg-yellow-900/20 dark:border-yellow-800 dark:text-yellow-400">
        <x-lucide-alert-triangle class="size-4" />
        @if ($role)
            Belum ada server WHM dengan role <strong>{{ $role }}</strong>
        @else
            Belum ada server WHM dikonfigurasi
        @endif
    </div>
@elseif ($hasMultiple)
    <x-nawasara-ui::filter-dropdown
        label="Server"
        :model="$model"
        :items="collect($servers)->mapWithKeys(function ($s) use ($whm) {
            $r = $whm->roleOf($s);
            $label = $r ? $s.' ('.$r.')' : $s;
            return [$s => $label];
        })->all()" />
@else
    {{-- Single server: show as label, no dropdown --}}
    @php
        $only = $servers[0];
        $role = $whm->roleOf($only);
    @endphp
    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-gray-200 bg-gray-50 dark:bg-neutral-800 dark:border-neutral-700 text-sm">
        <x-lucide-server class="size-4 text-gray-500 dark:text-neutral-400" />
        <span class="font-medium text-gray-700 dark:text-neutral-300">{{ $only }}</span>
        @if ($role)
            <span class="px-1.5 py-0.5 rounded text-xs font-medium {{ $role === 'mail' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' : ($role === 'hosting' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-700 dark:bg-neutral-700 dark:text-neutral-400') }}">
                {{ $role }}
            </span>
        @endif
    </div>
@endif
