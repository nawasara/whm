<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'WHM Hosting', 'url' => '#'], ['label' => 'Mail Queue']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page.title>Mail Queue</x-nawasara-ui::page.title>
        <p class="text-sm text-gray-500 dark:text-neutral-400 mb-4">
            Exim mail queue di server mail. Data live dari SSH — tidak di-cache.
        </p>

        @livewire('nawasara-whm.mail-queue.section.table')
    </x-nawasara-ui::page.container>
</div>
