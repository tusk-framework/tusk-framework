<?php

namespace Tusk\Events;

use Psr\EventDispatcher\StoppableEventInterface;
use Tusk\Contracts\Events\EventDispatcherInterface;
use Tusk\Contracts\Events\ListenerProviderInterface;

class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private ListenerProviderInterface $provider
    ) {}

    public function dispatch(object $event): object
    {
        $listeners = $this->provider->getListenersForEvent($event);

        foreach ($listeners as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }

        return $event;
    }
}
