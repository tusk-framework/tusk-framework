<?php

namespace Tusk\Events\Queue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;

class DatabaseQueue implements QueueInterface
{
    private string $table = 'jobs';

    public function __construct(private Connection $connection)
    {
        $this->ensureTableExists();
    }

    public function push(string $jobClass, array $payload = []): void
    {
        $this->connection->insert($this->table, [
            'job_class' => $jobClass,
            'payload'   => json_encode($payload),
            'status'    => 'pending',
        ]);
    }

    public function pop(): ?array
    {
        return $this->connection->transactional(function (Connection $conn): ?array {
            $row = $conn->fetchAssociative(
                "SELECT id, job_class, payload FROM {$this->table} WHERE status = 'pending' ORDER BY id ASC LIMIT 1"
            );

            if (!$row) {
                return null;
            }

            $conn->update($this->table, ['status' => 'processing', 'reserved_at' => date('Y-m-d H:i:s')], ['id' => $row['id']]);

            return [
                'id'        => $row['id'],
                'job_class' => $row['job_class'],
                'payload'   => json_decode((string) $row['payload'], true),
            ];
        });
    }

    public function complete(int|string $jobId): void
    {
        $this->connection->delete($this->table, ['id' => $jobId]);
    }

    public function fail(int|string $jobId, \Throwable $e): void
    {
        $this->connection->update($this->table, [
            'status'    => 'failed',
            'exception' => $e->getMessage(),
        ], ['id' => $jobId]);
    }

    private function ensureTableExists(): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist([$this->table])) {
            return;
        }

        $table = new Table($this->table);
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('job_class', 'string', ['length' => 255]);
        $table->addColumn('payload', 'text');
        $table->addColumn('status', 'string', ['length' => 50, 'default' => 'pending']);
        $table->addColumn('reserved_at', 'datetime', ['notnull' => false]);
        $table->addColumn('exception', 'text', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP']);
        $table->setPrimaryKey(['id']);

        $schemaManager->createTable($table);
    }
}
