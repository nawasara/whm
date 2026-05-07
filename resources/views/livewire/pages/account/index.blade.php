<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'WHM / cPanel', 'url' => '#'], ['label' => 'Accounts']]" />
    </x-slot>

    {{-- Title + Tambah Akun action hoisted into the section component
         (which owns the server-switcher state + form modals). Index is
         a thin shell. --}}
    <x-nawasara-ui::page.container>
        @livewire('nawasara-whm.account.section.table')
    </x-nawasara-ui::page.container>
</div>
