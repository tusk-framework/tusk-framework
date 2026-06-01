<?php

namespace Tusk\Contracts\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Factory
{
    public function __construct(
        public string $provides,
        public string $scope = 'singleton'
    ) {}
}
