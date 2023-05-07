<?php

namespace Flyo\Laravel\Components;

use Flyo\Model\Page as ModelPage;
use Illuminate\View\Component;
use Illuminate\View\View;

class Page extends Component
{
    public function __construct(public ModelPage $page)
    {
        
    }

    public function render(): View
    {
        return view('flyo::page', ['page' => $this->page]);
    }
}