<?php

namespace Flyo\Laravel\Components;

use Flyo\Model\Page as ModelPage;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Page extends Component
{
    public function __construct(public ModelPage $page, public Factory $viewFactory) {}

    public function render(): View
    {
        return $this->viewFactory->make('flyo::page', ['page' => $this->page]);
    }
}
