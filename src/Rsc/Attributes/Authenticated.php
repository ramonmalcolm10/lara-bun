<?php

namespace LaraBun\Rsc\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Authenticated
{
    public function __construct(public ?string $guard = null) {}
}
