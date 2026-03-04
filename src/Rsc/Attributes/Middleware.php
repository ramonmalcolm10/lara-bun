<?php

namespace LaraBun\Rsc\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Middleware
{
    /** @var string[] */
    public array $middleware;

    public function __construct(string ...$middleware)
    {
        $this->middleware = $middleware;
    }
}
