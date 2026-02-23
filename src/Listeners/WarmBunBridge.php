<?php

namespace RamonMalcolm\LaraBun\Listeners;

use Laravel\Octane\Events\WorkerStarting;
use RamonMalcolm\LaraBun\BunBridge;

class WarmBunBridge
{
    public function handle(WorkerStarting $event): void
    {
        try {
            $bridge = $event->app->make(BunBridge::class);
            $bridge->ping();
        } catch (\Throwable) {
            // Bridge not running — don't block worker startup
        }
    }
}
