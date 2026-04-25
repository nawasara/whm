<?php

namespace Nawasara\Whm\Livewire\Email;

use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('nawasara-whm::livewire.pages.email.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
