<?php

namespace Tusk\Web\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Route
{
    public function __construct(
        public string $path,
        public array $methods = ['GET'],
        public ?string $name = null
    ) {
    }
}
