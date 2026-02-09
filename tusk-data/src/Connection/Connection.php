<?php

namespace Tusk\Data\Connection;

use PDO;
use Closure;

class Connection implements ConnectionInterface
{
    public function __construct(
        protected PDO $pdo,
        protected string $database = '',
        protected string $tablePrefix = '',
        protected array $config = []
    ) {
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function select(string $query, array $bindings = []): array
    {
        return $this->run($query, $bindings, function ($stmt) {
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    public function insert(string $query, array $bindings = []): bool
    {
        return $this->run($query, $bindings, function ($stmt) {
            return true;
        });
    }

    public function update(string $query, array $bindings = []): int
    {
        return $this->run($query, $bindings, function ($stmt) {
            return $stmt->rowCount();
        });
    }

    public function delete(string $query, array $bindings = []): int
    {
        return $this->update($query, $bindings);
    }

    public function statement(string $query, array $bindings = []): bool
    {
        return $this->run($query, $bindings, function ($stmt) {
            return true;
        });
    }

    public function lastInsertId(): string|false
    {
        return $this->pdo->lastInsertId();
    }

    protected function run(string $query, array $bindings, Closure $callback)
    {
        $stmt = $this->pdo->prepare($query);

        // Bind values
        foreach ($bindings as $key => $value) {
            $stmt->bindValue(
                is_string($key) ? $key : $key + 1,
                $value,
                match (true) {
                    is_int($value) => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    is_null($value) => PDO::PARAM_NULL,
                    default => PDO::PARAM_STR
                }
            );
        }

        $stmt->execute();

        return $callback($stmt);
    }
}
