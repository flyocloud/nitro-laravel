<?php

namespace Flyo\Laravel\Components;

use Carbon\Carbon;
use Flyo\Model\ConfigResponse;
use Illuminate\Config\Repository;
use Illuminate\View\Component;

class DebugInfo extends Component
{
    public function __construct(public Repository $config, public ConfigResponse $configResponse) {}

    public function render()
    {
        $debug = $this->config->get('app.debug') ? 'true' : 'false';

        return '<!-- '.implode(' | ', [
            'debug:'.$debug,
            'env:'.$this->config->get('app.env'),
            'release:'.$this->config->get('app.version', '-'),
            'version:'.$this->configResponse->getNitro()->getVersion(),
            'versiondate:'.Carbon::createFromTimestamp($this->configResponse->getNitro()->getUpdatedAt(), $this->config->get('app.timezone'))->format('d.m.Y H:i'),
        ]).' -->';
    }
}
