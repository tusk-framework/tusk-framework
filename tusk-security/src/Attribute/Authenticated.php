<?php

namespace Tusk\Security\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Authenticated
{
}
