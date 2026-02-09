<?php

namespace Tusk\Events;

use Tusk\Contracts\Events\ListenerProviderInterface;

class ListenerProvider implements ListenerProviderInterface
{
    /** @var array<string, array<callable>> */
    private array $listeners = [];

    public function getListenersForEvent(object $event): iterable
    {
        $className = get_class($event);
        $listeners = $this->listeners[$className] ?? [];

        // Sort by priority if we stored it (not implementing priority sort yet for simplicity)
        yield from $listeners;

        // Also check for parent classes/interfaces? 
        // For v0.1 we stick to exact class match.
    }

    public function addListener(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }
}
