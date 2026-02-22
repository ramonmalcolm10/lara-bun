<?php

namespace RamonMalcolm\LaraBun\Ssr;

use Inertia\Ssr\Gateway;
use Inertia\Ssr\Response;
use RamonMalcolm\LaraBun\BunBridge;
use Throwable;

class BunSsrGateway implements Gateway
{
    public function __construct(private BunBridge $bridge) {}

    /**
     * Dispatch the Inertia page to the Bun SSR server.
     *
     * @param array<string, mixed> $page
     */
    public function dispatch(array $page): ?Response
    {
        if (! config('inertia.ssr.enabled', true)) {
            return null;
        }

        try {
            $result = $this->bridge->call('render', $page);

            if (! is_array($result) || ! isset($result['head'], $result['body'])) {
                return null;
            }

            return new Response(
                implode("\n", $result['head']),
                $result['body'],
            );
        } catch (Throwable) {
            return null;
        }
    }
}
