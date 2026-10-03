<?php

declare(strict_types=1);

namespace Tusk\Web\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Patch extends Route
{
    public function __construct(string $path = '/', array $middleware = [])
    {
        parent::__construct($path, ['PATCH'], null, $middleware);
    }
}
