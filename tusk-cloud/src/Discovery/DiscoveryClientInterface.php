<?php

namespace Tusk\Cloud\Discovery;

interface DiscoveryClientInterface
{
    /**
     * Get all ServiceInstances associated with a particular serviceId.
     *
     * @param string $serviceId The service ID to look up.
     * @return ServiceInstance[]
     */
    public function getInstances(string $serviceId): array;

    /**
     * Return all known service IDs.
     *
     * @return string[]
     */
    public function getServices(): array;
}
