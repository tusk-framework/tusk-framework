<?php

namespace Tusk\Cloud\Discovery;

class ServiceInstance
{
    public function __construct(
        public string $serviceId,
        public string $host,
        public int $port,
        public array $metadata = [],
        public bool $isSecure = false
    ) {
    }

    public function getUri(): string
    {
        $scheme = $this->isSecure ? 'https' : 'http';
        return sprintf('%s://%s:%d', $scheme, $this->host, $this->port);
    }
}
