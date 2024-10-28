<?php

namespace Flyo\Laravel\Components;

use Flyo\Model\BlockSlot;
use Illuminate\Contracts\View\Factory;
use Illuminate\View\Component;
use Illuminate\View\View;

class Slot extends Component
{
    public function __construct(public BlockSlot $container, public Factory $viewFactory) {}

    public function render(): View
    {
        return $this->viewFactory->make('flyo::slotcontainer', ['slotContainer' => $this->container]);
    }
}
