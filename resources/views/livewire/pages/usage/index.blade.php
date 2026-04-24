<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'WHM / cPanel', 'url' => '#'], ['label' => 'Usage Dashboard']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page.title>Resource Usage Dashboard</x-nawasara-ui::page.title>

        @livewire('nawasara-whm.usage.section.dashboard')
    </x-nawasara-ui::page.container>
</div>
