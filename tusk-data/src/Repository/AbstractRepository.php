<?php

namespace Tusk\Data\Repository;

use Tusk\Data\Contract\ConnectionInterface;

abstract class AbstractRepository
{
    public function __construct(
        protected ConnectionInterface $db
    ) {
    }

    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->db->query($sql, $params);
        return $rows[0] ?? null;
    }

    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->db->query($sql, $params);
    }
}
