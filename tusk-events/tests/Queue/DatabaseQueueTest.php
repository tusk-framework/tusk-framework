<?php

declare(strict_types=1);

namespace Tusk\Events\Tests\Queue;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Tusk\Events\Queue\DatabaseQueue;

final class DatabaseQueueTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('SQLite is required for the queue integration test.');
        }

        $path = tempnam(sys_get_temp_dir(), 'tusk-queue-');
        self::assertNotFalse($path);
        $this->databasePath = $path;
    }

    protected function tearDown(): void
    {
        if (isset($this->databasePath) && is_file($this->databasePath)) {
            @unlink($this->databasePath);
        }
    }

    public function test_two_consumers_do_not_claim_the_same_job(): void
    {
        $connectionParameters = ['driver' => 'pdo_sqlite', 'path' => $this->databasePath];
        $firstConnection = DriverManager::getConnection($connectionParameters);
        $secondConnection = DriverManager::getConnection($connectionParameters);
        $firstQueue = new DatabaseQueue($firstConnection);
        $secondQueue = new DatabaseQueue($secondConnection);

        $firstQueue->push('ExampleJob', ['value' => 1]);

        $first = $firstQueue->pop();
        $second = $secondQueue->pop();

        self::assertNotNull($first);
        self::assertNull($second);
    }

    public function test_stale_processing_jobs_can_be_reclaimed(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->databasePath,
        ]);
        $queue = new DatabaseQueue($connection, 60);
        $queue->push('ExampleJob', ['value' => 1]);

        $first = $queue->pop();
        self::assertNotNull($first);

        $connection->update('jobs', [
            'status' => 'processing',
            'reserved_at' => (new \DateTimeImmutable('-2 minutes'))->format('Y-m-d H:i:s'),
        ], ['id' => $first['id']]);

        $reclaimed = $queue->pop();

        self::assertNotNull($reclaimed);
        self::assertSame($first['id'], $reclaimed['id']);
    }
}
