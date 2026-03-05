<?php

namespace Tests;

use LaraBun\BunServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            BunServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('bun.socket_path', '/tmp/bun-bridge-test.sock');
        $app['config']->set('bun.rsc.enabled', true);
        $app['config']->set('bun.ssr.enabled', false);
    }
}
