<?php

namespace Tusk\Cloud\Http;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class ApiClient
{
    public function __construct(
        public string $serviceId,
        public string $path = ''
    ) {
    }
}
