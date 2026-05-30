<?php

namespace Tusk\Events\Queue;

use Tusk\Data\DB;

class DatabaseQueue implements QueueInterface
{
    private string $table = 'jobs';

    public function push(string $jobClass, array $payload = []): void
    {
        $this->ensureTableExists();

        $stmt = DB::connection()->getPdo()->prepare(
            "INSERT INTO {$this->table} (job_class, payload, status) VALUES (?, ?, 'pending')"
        );
        $stmt->execute([$jobClass, json_encode($payload)]);
    }

    public function pop(): ?array
    {
        $this->ensureTableExists();

        $pdo = DB::connection()->getPdo();

        $pdo->beginTransaction();

        // Very basic implementation, real queue needs 'FOR UPDATE SKIP LOCKED' or similar depending on DB driver
        $stmt = $pdo->query("SELECT id, job_class, payload FROM {$this->table} WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
        $job = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($job) {
            $update = $pdo->prepare("UPDATE {$this->table} SET status = 'processing', reserved_at = CURRENT_TIMESTAMP WHERE id = ?");
            $update->execute([$job['id']]);
            $pdo->commit();

            return [
                'id' => $job['id'],
                'job_class' => $job['job_class'],
                'payload' => json_decode($job['payload'], true),
            ];
        }

        $pdo->commit();

        return null;
    }

    public function complete(int|string $jobId): void
    {
        $stmt = DB::connection()->getPdo()->prepare("DELETE FROM {$this->table} WHERE id = ?");
        $stmt->execute([$jobId]);
    }

    public function fail(int|string $jobId, \Throwable $e): void
    {
        $stmt = DB::connection()->getPdo()->prepare(
            "UPDATE {$this->table} SET status = 'failed', exception = ? WHERE id = ?"
        );
        $stmt->execute([$e->getMessage(), $jobId]);
    }

    private function ensureTableExists(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_class VARCHAR(255) NOT NULL,
            payload TEXT NOT NULL,
            status VARCHAR(50) DEFAULT 'pending',
            reserved_at TIMESTAMP NULL,
            exception TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        DB::connection()->getPdo()->exec($sql);
    }
}
