<?php

namespace Nawasara\Whm\Livewire\Server;

use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('nawasara-whm::livewire.pages.server.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
