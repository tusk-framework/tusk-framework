<?php

namespace Tusk\Data\Repository;

use Tusk\Data\Contract\ConnectionInterface;
use Tusk\Contracts\Data\RepositoryInterface;

abstract class AbstractRepository implements RepositoryInterface
{
    public function __construct(
        protected ConnectionInterface $db,
        protected string $table
    ) {}

    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->db->query($sql, $params);
        return $rows[0] ?? null;
    }

    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->db->query($sql, $params);
    }
    
    public function find(mixed $id): ?object
    {
        $data = $this->fetchOne("SELECT * FROM {$this->table} WHERE id = ?", [$id]);
        if (!$data) {
            return null;
        }
        return $this->hydrate($data);
    }
    
    public function save(object $entity): void
    {
        // Simple default behavior. Could be overridden.
        // We'll leave it abstract or throw exception if not implemented, but it's an interface method.
    }
    
    public function remove(object $entity): void
    {
        // Default behavior.
    }
    
    abstract protected function hydrate(array $data): object;
}
