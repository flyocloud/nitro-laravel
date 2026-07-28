<?php

namespace Flyo\Laravel\Components;

use Flyo\Model\BlockSlotValue;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Slot extends Component
{
    public function __construct(public BlockSlotValue $container, public Factory $viewFactory) {}

    public function render(): View
    {
        return $this->viewFactory->make('flyo::slotcontainer', ['slotContainer' => $this->container]);
    }
}
