<?php

namespace Tusk\Contracts\Data;

interface TransactionManagerInterface
{
    /**
     * Executes a callable within a transaction.
     *
     * @param callable $callback The code to execute within the transaction.
     * @return mixed The result of the callback.
     * @throws \Exception If the transaction fails and is rolled back.
     */
    public function transactional(callable $callback): mixed;

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
    public function rollBack(): void;
}
