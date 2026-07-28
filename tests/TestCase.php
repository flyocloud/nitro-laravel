<?php

namespace Flyo\Laravel\Tests;

use Flyo\Laravel\Components\Head;
use Flyo\Laravel\ServiceProvider;
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
    }

    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // a token is required by the service provider, no request is made while testing because
        // the application is running in console.
        $app['config']->set('flyo.token', 'test-token');
        $app['config']->set('flyo.live_edit', true);
    }
}
