<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'WHM Hosting', 'url' => '#'], ['label' => 'Mail Queue']]" />
    </x-slot>

    {{-- Title + actions hoisted into the section component (which owns
         the server-switcher + reactive queue state). Index is a shell. --}}
    <x-nawasara-ui::page.container>
        @livewire('nawasara-whm.mail-queue.section.table')
    </x-nawasara-ui::page.container>
</div>
