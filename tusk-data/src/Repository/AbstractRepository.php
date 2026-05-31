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
        throw new \BadMethodCallException(static::class . ' must implement save() or use a concrete repository adapter.');
    }
    
    public function remove(object $entity): void
    {
        throw new \BadMethodCallException(static::class . ' must implement remove() or use a concrete repository adapter.');
    }
    
    abstract protected function hydrate(array $data): object;
}
