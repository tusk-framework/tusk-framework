<?php

namespace Tusk\Events;

use ReflectionClass;
use ReflectionException;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Events\ListenerProviderInterface;
use Tusk\Events\Attributes\EventListener;

class ListenerScanner
{
    public function __construct(
        private ContainerInterface $container,
        private ListenerProvider $provider // Concrete for now to access addListener
    ) {
    }

    public function scan(array $serviceIds): void
    {
        foreach ($serviceIds as $id) {
            try {
                $service = $this->container->get($id);
                $reflection = new ReflectionClass($service);

                foreach ($reflection->getMethods() as $method) {
                    $attributes = $method->getAttributes(EventListener::class);

                    if (!empty($attributes)) {
                        // Assumption: First parameter is the Event class
                        $params = $method->getParameters();
                        if (count($params) === 0) {
                            continue;
                        }

                        $eventType = $params[0]->getType();
                        if (!$eventType instanceof \ReflectionNamedType || $eventType->isBuiltin()) {
                            continue;
                        }

                        $eventClass = $eventType->getName();

                        $this->provider->addListener($eventClass, [$service, $method->getName()]);
                    }
                }
            } catch (ReflectionException) {
                // Ignore
            }
        }
    }
}
