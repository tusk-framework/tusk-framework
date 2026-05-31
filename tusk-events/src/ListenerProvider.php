<?php

namespace Tusk\Events;

use Tusk\Contracts\Events\ListenerProviderInterface;

class ListenerProvider implements ListenerProviderInterface
{
    /** @var array<string, array<array{0: string, 1: string}>> */
    private array $listenerMap = [];

    public function __construct(
        private \Tusk\Contracts\Container\ContainerInterface $container,
        array $listenerMap = []
    ) {
        $this->listenerMap = $listenerMap;
    }

    public function getListenersForEvent(object $event): iterable
    {
        $className = get_class($event);
        $listeners = $this->listenerMap[$className] ?? [];

        foreach ($listeners as $listener) {
            $serviceId = $listener[0];
            $method = $listener[1];
            
            // Resolve the listener lazily when the event is dispatched
            $service = $this->container->get($serviceId);
            
            // Return a callable structure
            yield [$service, $method];
        }
    }
}
