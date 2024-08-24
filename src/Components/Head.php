<?php

namespace Flyo\Laravel\Components;

use Flyo\Model\Page as ModelPage;
use Illuminate\Contracts\View\Factory;
use Illuminate\View\Component;

class Head extends Component
{
    public static array $scripts = [];

    public function __construct(public ModelPage $page, public Factory $viewFactory) {}

    public function render(): string
    {
        $script = '<script>';

        foreach (self::$scripts as $s) {
            $script .= $s;
        }

        return $script.'</script>';
    }
}
