<?php

namespace Nawasara\Whm\Livewire\Usage;

use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('nawasara-whm::livewire.pages.usage.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
