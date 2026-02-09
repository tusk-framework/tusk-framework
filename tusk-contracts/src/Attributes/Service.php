<?php

namespace Tusk\Contracts\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Service
{
    public function __construct(
        public string $scope = 'singleton'
    ) {
    }
}
