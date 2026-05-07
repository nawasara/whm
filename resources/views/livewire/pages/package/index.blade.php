<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'WHM / cPanel', 'url' => '#'], ['label' => 'Packages']]" />
    </x-slot>

    {{-- Title + Tambah Package action hoisted into the section component
         (server-switcher state lives there). Index is a shell. --}}
    <x-nawasara-ui::page.container>
        @livewire('nawasara-whm.package.section.table')
    </x-nawasara-ui::page.container>
</div>
