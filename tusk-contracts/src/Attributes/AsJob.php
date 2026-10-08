<?php

namespace Tusk\Contracts\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsJob
{
    public function __construct(public readonly string $name) {}
}
