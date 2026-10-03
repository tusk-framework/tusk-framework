<?php

declare(strict_types=1);

namespace Tusk\Web\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Put extends Route
{
    public function __construct(string $path = '/', array $middleware = [])
    {
        parent::__construct($path, ['PUT'], null, $middleware);
    }
}
