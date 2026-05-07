<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'WHM Hosting', 'url' => '#'], ['label' => 'Email Accounts']]" />
    </x-slot>

    {{-- Title + Tambah Email action hoisted into the section component
         (which owns the server-switcher + form modals). Index is a shell. --}}
    <x-nawasara-ui::page.container>
        @livewire('nawasara-whm.email.section.table')
    </x-nawasara-ui::page.container>
</div>
