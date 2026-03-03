<?php

namespace RamonMalcolm\LaraBun\Rsc\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Can
{
    public function __construct(
        public string $ability,
        public ?string $model = null,
    ) {}
}
