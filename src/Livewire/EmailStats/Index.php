<?php

namespace Nawasara\Whm\Livewire\EmailStats;

use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('nawasara-whm::livewire.pages.email-stats.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
