<?php

namespace Tusk\Cloud\Discovery;

class ConsulDiscoveryClient implements DiscoveryClientInterface
{
    public function __construct(
        private string $consulHost = 'http://localhost:8500'
    ) {
    }

    public function getInstances(string $serviceId): array
    {
        $url = sprintf('%s/v1/health/service/%s?passing=true', $this->consulHost, $serviceId);

        try {
            // Using a simple stream context for zero-dep HTTP call
            $json = @file_get_contents($url);

            if ($json === false) {
                return [];
            }

            $data = json_decode($json, true);
            $instances = [];

            foreach ($data as $node) {
                $service = $node['Service'];
                $instances[] = new ServiceInstance(
                    serviceId: $service['Service'],
                    host: $service['Address'] ?: $node['Node']['Address'],
                    port: $service['Port'],
                    metadata: $service['Meta'] ?? [],
                    isSecure: false // Consul tags could determine this
                );
            }

            return $instances;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getServices(): array
    {
        $url = sprintf('%s/v1/catalog/services', $this->consulHost);

        try {
            $json = @file_get_contents($url);

            if ($json === false) {
                return [];
            }

            return array_keys(json_decode($json, true) ?: []);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
