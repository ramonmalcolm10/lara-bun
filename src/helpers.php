<?php

use RamonMalcolm\LaraBun\Rsc\RscResponse;

if (! function_exists('rsc')) {
    /**
     * @param  array<string, mixed>  $props
     */
    function rsc(string $component, array $props = []): RscResponse
    {
        return new RscResponse($component, $props);
    }
}
