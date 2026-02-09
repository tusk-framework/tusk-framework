<?php

namespace Tusk\Contracts\Adapter;

/**
 * Marker interface for all Tusk Adapters.
 * 
 * Adapters are the bridge between the Application Domain and external infrastructure (HTTP, SQL, Messaging).
 */
interface AdapterInterface
{
    /**
     * Optional: Returns the adapter metadata or capabilities.
     */
    public function getMetadata(): array;
}
