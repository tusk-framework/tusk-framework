<?php

namespace Tests\Unit\Data\Query;

use PHPUnit\Framework\TestCase;
use Tusk\Data\Contract\ConnectionInterface;
use Tusk\Data\Query\Builder;

class BuilderTest extends TestCase
{
    public function test_update_returns_execute_result(): void
    {
        $connection = new class implements ConnectionInterface {
            public array $executed = [];

            public function execute(string $sql, array $params = []): bool
            {
                $this->executed[] = [$sql, $params];

                return false;
            }

            public function query(string $sql, array $params = []): array
            {
                return [];
            }

            public function lastInsertId(): string|int
            {
                return 1;
            }

            public function ping(): bool
            {
                return true;
            }

            public function beginTransaction(): void {}

            public function commit(): void {}

            public function rollback(): void {}
        };

        $result = (new Builder($connection))
            ->table('users')
            ->where('id', 1)
            ->update(['name' => 'Taylor']);

        $this->assertFalse($result);
        $this->assertSame(
            [['UPDATE users SET name = ? WHERE id = ?', ['Taylor', 1]]],
            $connection->executed
        );
    }

    public function test_delete_returns_execute_result(): void
    {
        $connection = new class implements ConnectionInterface {
            public function execute(string $sql, array $params = []): bool
            {
                return false;
            }

            public function query(string $sql, array $params = []): array
            {
                return [];
            }

            public function lastInsertId(): string|int
            {
                return 1;
            }

            public function ping(): bool
            {
                return true;
            }

            public function beginTransaction(): void {}

            public function commit(): void {}

            public function rollback(): void {}
        };

        $result = (new Builder($connection))
            ->table('users')
            ->where('id', 1)
            ->delete();

        $this->assertFalse($result);
    }
}
