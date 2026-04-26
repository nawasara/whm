<?php

namespace Nawasara\Whm\Livewire\MailLog;

use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('nawasara-whm::livewire.pages.mail-log.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
