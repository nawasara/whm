<?php

namespace Nawasara\Whm\Livewire\Account;

use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('nawasara-whm::livewire.pages.account.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
