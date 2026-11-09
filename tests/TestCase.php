<?php

namespace Flyo\Laravel\Tests;

use Flyo\Laravel\Components\Head;
use Flyo\Laravel\DraftMode;
use Flyo\Laravel\PageRoutes;
use Flyo\Laravel\ServiceProvider;
use Illuminate\Routing\Route;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // the head component collects its scripts and metas in static properties
        Head::$scripts = [];
        Head::$metas = [];
        Head::$jsonLd = [];

        // the draft state belongs to a single response, see DraftMode
        DraftMode::reset();
    }

    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    /**
     * The route of the given name, looked up without the name index of the route collection, which
     * Laravel only refreshes once the routes of the application are loaded.
     */
    protected function namedRoute(string $name): ?Route
    {
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }

        return null;
    }

    /**
     * The fallback route which serves the CMS pages.
     */
    protected function pageRoute(): ?Route
    {
        return $this->namedRoute(PageRoutes::NAME);
    }

    protected function defineEnvironment($app): void
    {
        // a token is required by the service provider, no request is made while testing because
        // the application is running in console.
        $app['config']->set('flyo.token', 'test-token');
        $app['config']->set('flyo.live_edit', true);
    }
}
