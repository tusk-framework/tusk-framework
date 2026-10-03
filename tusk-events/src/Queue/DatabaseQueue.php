<?php

namespace Tusk\Events\Queue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;

class DatabaseQueue implements QueueInterface
{
    private string $table = 'jobs';

    public function __construct(
        private Connection $connection,
        private int $reservationTimeoutSeconds = 300
    )
    {
        if ($this->reservationTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Reservation timeout must be positive.');
        }

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
            $now = new \DateTimeImmutable('now');
            $nowString = $now->format('Y-m-d H:i:s');
            $staleBefore = $now->modify(sprintf('-%d seconds', $this->reservationTimeoutSeconds));

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $row = $conn->fetchAssociative(
                    "SELECT id, job_class, payload, status, reserved_at
                     FROM {$this->table}
                     WHERE status = 'pending'
                        OR (status = 'processing' AND reserved_at IS NOT NULL AND reserved_at <= :stale_before)
                     ORDER BY id ASC
                     LIMIT 1",
                    ['stale_before' => $staleBefore->format('Y-m-d H:i:s')]
                );

                if (!$row) {
                    return null;
                }

                // The conditional update is the claim. A concurrent consumer can
                // select the same candidate, but only one update can observe it
                // as pending or stale before the other changes its status.
                $affected = $conn->executeStatement(
                    "UPDATE {$this->table}
                     SET status = 'processing', reserved_at = :reserved_at
                     WHERE id = :id
                       AND (
                            status = 'pending'
                            OR (status = 'processing' AND reserved_at IS NOT NULL AND reserved_at <= :stale_before)
                       )",
                    [
                        'reserved_at' => $nowString,
                        'id' => $row['id'],
                        'stale_before' => $staleBefore->format('Y-m-d H:i:s'),
                    ]
                );

                if ($affected !== 1) {
                    continue;
                }

                return [
                    'id'        => $row['id'],
                    'job_class' => $row['job_class'],
                    'payload'   => json_decode((string) $row['payload'], true),
                ];
            }

            return null;
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
