<?php

declare(strict_types=1);

namespace Tusk\Runtime\RoadRunner;

use Spiral\Goridge\RPC\RPCInterface;

interface RoadRunnerRpcFactoryInterface
{
    public function create(): RPCInterface;
}
