<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'WHM Hosting', 'url' => '#'], ['label' => 'Email Stats']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page.title>Email Stats</x-nawasara-ui::page.title>
        <p class="text-sm text-gray-500 dark:text-neutral-400 mb-4">
            Aktivitas email server mail. Auto-refresh setiap 60 detik.
        </p>

        @livewire('nawasara-whm.email-stats.section.overview')
    </x-nawasara-ui::page.container>
</div>
