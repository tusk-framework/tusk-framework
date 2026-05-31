<?php

namespace Tusk\Contracts\Data;

interface RepositoryInterface
{
    /**
     * Finds an entity by its primary key.
     *
     * @param mixed $id
     * @return object|null
     */
    public function find(mixed $id): ?object;

    /**
     * Saves an entity to the repository.
     *
     * @param object $entity
     */
    public function save(object $entity): void;

    /**
     * Removes an entity from the repository.
     *
     * @param object $entity
     */
    public function remove(object $entity): void;
}
