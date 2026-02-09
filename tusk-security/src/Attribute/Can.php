<?php

namespace Tusk\Security\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Can
{
    public function __construct(
        public string $ability,
        public mixed $subject = null
    ) {
    }
}
