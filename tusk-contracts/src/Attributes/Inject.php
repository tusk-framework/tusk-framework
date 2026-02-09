<?php

namespace Tusk\Contracts\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Inject
{
    public function __construct(
        public ?string $id = null
    ) {
    }
}
