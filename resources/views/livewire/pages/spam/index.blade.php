<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'WHM Hosting', 'url' => '#'], ['label' => 'Mail Security']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page.title>Mail Security</x-nawasara-ui::page.title>
        <p class="text-sm text-gray-500 dark:text-neutral-400 mb-4">
            Connection yang ditolak server mail — auth failure, RBL, unknown user, spam. Auto-refresh tiap 60 detik.
        </p>

        @livewire('nawasara-whm.spam.section.overview')
    </x-nawasara-ui::page.container>
</div>
