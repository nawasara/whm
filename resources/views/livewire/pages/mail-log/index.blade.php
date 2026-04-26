<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'WHM Hosting', 'url' => '#'], ['label' => 'Mail Log']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page.title>Mail Log Search</x-nawasara-ui::page.title>
        <p class="text-sm text-gray-500 dark:text-neutral-400 mb-4">
            Cari di Exim mainlog server. Query langsung via SSH, log tidak di-transfer — efisien untuk file GB-an.
        </p>

        @livewire('nawasara-whm.mail-log.section.search')
    </x-nawasara-ui::page.container>
</div>
