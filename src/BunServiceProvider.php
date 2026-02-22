<?php

namespace RamonMalcolm\LaraBun;

use Illuminate\Support\ServiceProvider;
use RamonMalcolm\LaraBun\Console\BunServeCommand;
use RamonMalcolm\LaraBun\Ssr\BunSsrGateway;

class BunServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/bun.php', 'bun');

        $this->app->singleton('bun-bridge', function () {
            return new BunBridge;
        });

        $this->app->alias('bun-bridge', BunBridge::class);

        if (config('bun.ssr.enabled') && interface_exists(\Inertia\Ssr\Gateway::class)) {
            $this->app->singleton(\Inertia\Ssr\Gateway::class, BunSsrGateway::class);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/bun.php' => config_path('bun.php'),
            ], 'lara-bun-config');

            $this->commands([
                BunServeCommand::class,
            ]);
        }
    }
}
