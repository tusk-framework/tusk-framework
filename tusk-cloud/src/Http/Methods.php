<?php

namespace Tusk\Cloud\Http;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Get
{
    public function __construct(public string $path)
    {
    }
}

#[Attribute(Attribute::TARGET_METHOD)]
class Post
{
    public function __construct(public string $path)
    {
    }
}
