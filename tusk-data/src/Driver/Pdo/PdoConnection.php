<?php

namespace Tusk\Data\Driver\Pdo;

use PDO;
use PDOException;
use Tusk\Data\Contract\ConnectionInterface;
use RuntimeException;

class PdoConnection implements ConnectionInterface
{
    private ?PDO $pdo = null;

    public function __construct(
        private string $dsn,
        private ?string $username = null,
        private ?string $password = null,
        private array $options = []
    ) {
        // Set default options
        $this->options = $options + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    private function connect(): void
    {
        if ($this->pdo) {
            return;
        }

        try {
            $this->pdo = new PDO($this->dsn, $this->username, $this->password, $this->options);
        } catch (PDOException $e) {
            throw new RuntimeException("Database connection failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function execute(string $sql, array $params = []): bool
    {
        $this->connect();

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            // Here we could handle reconnection logic if "MySQL server has gone away"
            throw $e;
        }
    }

    public function query(string $sql, array $params = []): array
    {
        $this->connect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function lastInsertId(): string|int
    {
        $this->connect();
        return $this->pdo->lastInsertId();
    }

    public function ping(): bool
    {
        try {
            // Simple ping strategy
            $this->query('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function beginTransaction(): void
    {
        $this->connect();
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        if ($this->pdo && $this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollback(): void
    {
        if ($this->pdo && $this->pdo->inTransaction()) {
            $this->pdo->rollback();
        }
    }
}
