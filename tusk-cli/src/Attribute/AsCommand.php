<?php

namespace Tusk\Cli\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class AsCommand
{
    public function __construct(
        public string $name,
        public string $description = ''
    ) {}
}
