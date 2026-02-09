<?php

namespace Tusk\Events\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class EventListener
{
    public function __construct(
        public ?int $priority = 0
    ) {
    }
}
