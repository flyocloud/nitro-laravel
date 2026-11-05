<?php

namespace Flyo\Laravel\Components;

use Flyo\Model\Block as ModelBlock;
use Illuminate\Config\Repository;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Env;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;

class Block extends Component
{
    public function __construct(public ModelBlock $block, public Factory $viewFactory, public Repository $configRepository) {}

    /**
     * Plain strings are never returned, laravel would compile them as blade template.
     */
    public function render(): View|HtmlString
    {
        $viewFile = $this->configRepository->get('flyo.views_namespace', 'flyo').'.'.$this->block->getComponent();

        if ($this->viewFactory->exists($viewFile)) {
            return $this->viewFactory->make($viewFile, ['block' => $this->block]);
        }

        if (Env::get('APP_DEBUG')) {
            $expectedPath = 'resources/views/'.$this->configRepository->get('flyo.views_namespace', 'flyo').'/'.$this->block->getComponent().'.blade.php';

            return new HtmlString('<div style="background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 10px; margin: 10px 0;">Component view not found: '.e($expectedPath).'</div>');
        }

        return new HtmlString('');
    }
}
