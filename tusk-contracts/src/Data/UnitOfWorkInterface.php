<?php

namespace Tusk\Contracts\Data;

interface UnitOfWorkInterface
{
    /**
     * Marks an entity to be saved to the database.
     */
    public function persist(object $entity): void;

    /**
     * Marks an entity to be deleted from the database.
     */
    public function remove(object $entity): void;

    /**
     * Commits all scheduled operations (inserts, updates, deletes) to the database.
     */
    public function commit(): void;

    /**
     * Clears the UnitOfWork state, detaching all managed entities.
     * Essential for Long-Lived workers.
     */
    public function clear(): void;
}
