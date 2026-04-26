<?php

namespace Nawasara\Whm\Livewire\Spam;

use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('nawasara-whm::livewire.pages.spam.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
