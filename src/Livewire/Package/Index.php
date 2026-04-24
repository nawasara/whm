<?php

namespace Nawasara\Whm\Livewire\Package;

use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('nawasara-whm::livewire.pages.package.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
