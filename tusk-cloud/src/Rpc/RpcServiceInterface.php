<?php

namespace Tusk\Cloud\Rpc;

interface RpcServiceInterface
{
    /**
     * Returns the service name (e.g., "greeter.Greeter").
     */
    public static function getServiceName(): string;

    /**
     * Handle an RPC call.
     * 
     * @param string $method The method name being called.
     * @param mixed $payload The input data (protobuf object or array).
     * @return mixed The output data.
     */
    public function handle(string $method, mixed $payload): mixed;
}
