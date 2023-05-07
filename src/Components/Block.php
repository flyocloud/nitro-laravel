<?php

namespace Flyo\Laravel\Components;

use Flyo\Model\Block as ModelBlock;
use Illuminate\View\Component;

class Block extends Component
{
    public function __construct(public ModelBlock $block)
    {
        
    }

    public function render()
    {
        return view('flyo.' . $this->block->getComponent(), ['block' => $this->block]);
    }
}