<?php

namespace Tusk\Data\Contract;

interface ConnectionInterface
{
    /**
     * Executes a statement and returns true on success.
     * Useful for INSERT/UPDATE/DELETE.
     */
    public function execute(string $sql, array $params = []): bool;

    /**
     * Executes a statement and returns the result set.
     * Useful for SELECT.
     */
    public function query(string $sql, array $params = []): array;

    /**
     * Returns the last inserted ID.
     */
    public function lastInsertId(): string|int;

    /**
     * Checks if the connection is alive.
     */
    public function ping(): bool;

    /**
     * Begins a transaction.
     */
    public function beginTransaction(): void;

    /**
     * Commits the current transaction.
     */
    public function commit(): void;

    /**
     * Rolls back the current transaction.
     */
    public function rollback(): void;
}
