<?php

namespace RamonMalcolm\LaraBun\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed call(string $function, array $args = [])
 * @method static array ssr(array $page)
 * @method static array<int, string> list()
 * @method static bool ping()
 * @method static void disconnect()
 *
 * @see \RamonMalcolm\LaraBun\BunBridge
 */
class Bun extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \RamonMalcolm\LaraBun\BunBridge::class;
    }
}
