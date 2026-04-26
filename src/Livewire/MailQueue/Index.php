<?php

namespace Nawasara\Whm\Livewire\MailQueue;

use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('nawasara-whm::livewire.pages.mail-queue.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
