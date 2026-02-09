<?php

namespace Tusk\Data\Connection;

use PDO;

interface ConnectionInterface
{
    public function getPdo(): PDO;
    public function select(string $query, array $bindings = []): array;
    public function insert(string $query, array $bindings = []): bool;
    public function update(string $query, array $bindings = []): int;
    public function delete(string $query, array $bindings = []): int;
    public function statement(string $query, array $bindings = []): bool;
    public function lastInsertId(): string|false;
}
