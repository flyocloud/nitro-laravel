<?php

namespace Flyo\Laravel\Components;

use Carbon\Carbon;
use Flyo\Model\ConfigResponse;
use Illuminate\Config\Repository;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;

class DebugInfo extends Component
{
    public function __construct(public Repository $config, public ConfigResponse $configResponse) {}

    /**
     * The comment is returned as html string, a plain string would be compiled as blade template by
     * laravel.
     *
     * @return HtmlString
     */
    public function render()
    {
        $debug = $this->config->get('app.debug') ? 'true' : 'false';

        $flyoToken = (string) $this->config->get('flyo.token', '');
        $tokenType = str_starts_with($flyoToken, 'p-') ? 'production' : (str_starts_with($flyoToken, 'd-') ? 'develop' : 'unknown');

        return new HtmlString('<!-- '.e(implode(' | ', [
            'debug:'.$debug,
            'env:'.$this->config->get('app.env'),
            'tokentype:'.$tokenType,
            'release:'.$this->config->get('app.version', '-'),
            'version:'.$this->configResponse->getNitro()->getVersion(),
            'versiondate:'.Carbon::createFromTimestamp($this->configResponse->getNitro()->getUpdatedAt(), $this->config->get('app.timezone'))->format('d.m.Y H:i'),
        ])).' -->');
    }
}
