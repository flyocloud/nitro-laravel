<?php

namespace Flyo\Laravel\Tests\Fakes;

use Flyo\Api\PagesApi;
use Flyo\Model\Page;

/**
 * Stands in for the pages endpoint, answers every path with a page titled after it and records the
 * path and the locale of every request instead of sending one.
 */
class PagesApiFake extends PagesApi
{
    /**
     * @var array<int, array{0: string|null, 1: string|null}> path and locale of every page request
     */
    public array $requests = [];

    public function __construct() {}

    public function page($slug = null, $lang = null, string $contentType = self::contentTypes['page'][0])
    {
        $this->requests[] = [$slug, $lang];

        return new Page(['title' => 'title of '.$slug, 'href' => '/'.ltrim((string) $slug, '/')]);
    }
}
