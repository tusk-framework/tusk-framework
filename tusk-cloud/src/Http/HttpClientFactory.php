<?php

namespace Tusk\Cloud\Http;

use Tusk\Cloud\Discovery\DiscoveryClientInterface;
use ReflectionClass;
use ReflectionAttribute;

class HttpClientFactory
{
    public function __construct(
        private DiscoveryClientInterface $discovery
    ) {
    }

    public function create(string $interface): object
    {
        $reflection = new ReflectionClass($interface);
        $attributes = $reflection->getAttributes(ApiClient::class);

        if (empty($attributes)) {
            throw new \InvalidArgumentException("Interface $interface is not marked with #[ApiClient]");
        }

        $apiClient = $attributes[0]->newInstance();
        $serviceId = $apiClient->serviceId;

        // Dynamic Proxy Generation using anonymous class (simplified for v0.1)
        // In a real scenario, we would use a library like occlusion/proxy-manager or generating code.
        // For this POC, we return a generic caller that validates the concept.

        return new class ($this->discovery, $serviceId) {
            public function __construct(
            private DiscoveryClientInterface $discovery,
            private string $serviceId
            ) {}

            public function __call(string $name, array $arguments)
            {
                // 1. Discover Service
                $instances = $this->discovery->getInstances($this->serviceId);
                if (empty($instances)) {
                    throw new \RuntimeException("No instances found for service: {$this->serviceId}");
                }

                // Simple Load Balancing (Round Robin / Random)
                $instance = $instances[array_rand($instances)];
                $baseUrl = $instance->getUri();

                // 2. Resolve Method Attribute (Get/Post)
                // This is tricky in a dynamic __call without the interface reflection map available here.
                // For this POC, we assume the method name maps to GET /$name

                $path = strtolower($name);
                $url = "$baseUrl/$path";

                // 3. Execute Request
                echo "[ApiClient] Calling $url ...\n";
                // return file_get_contents($url); // Mocked return
                return ["id" => 1, "status" => "mocked_success"];
            }
        };
    }
}
