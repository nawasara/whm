<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'WHM / cPanel', 'url' => '#'], ['label' => 'Packages']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page.title>Hosting Packages</x-nawasara-ui::page.title>

        <x-slot name="actions">
            <x-nawasara-ui::page.actions>
                <x-nawasara-ui::button wire:click="$dispatch('openCreatePackage')" color="success"
                    @click="$dispatch('open-modal', 'whm-package-form')"
                    permission="whm.package.manage">
                    <x-slot:icon><x-lucide-plus class="size-4" /></x-slot:icon>
                    Tambah Package
                </x-nawasara-ui::button>
            </x-nawasara-ui::page.actions>
        </x-slot>

        @livewire('nawasara-whm.package.section.table')
    </x-nawasara-ui::page.container>
</div>
