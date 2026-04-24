<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'WHM / cPanel', 'url' => '#'], ['label' => 'Server Status']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page.title>Server Status</x-nawasara-ui::page.title>

        @livewire('nawasara-whm.server.section.overview')
    </x-nawasara-ui::page.container>
</div>
