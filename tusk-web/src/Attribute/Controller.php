<?php

declare(strict_types=1);

namespace Tusk\Web\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Controller
{
    public function __construct(
        public string $prefix = ''
    ) {}
}
