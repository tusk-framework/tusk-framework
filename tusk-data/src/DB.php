<?php

namespace Tusk\Data;

use Tusk\Data\Connection\ConnectionInterface;
use Tusk\Data\Query\Builder;

class DB
{
    protected static ConnectionInterface $connection;

    public static function setConnection(ConnectionInterface $connection): void
    {
        self::$connection = $connection;
    }

    public static function connection(): ConnectionInterface
    {
        return self::$connection;
    }

    public static function table(string $table): Builder
    {
        return (new Builder(self::$connection))->table($table);
    }

    public static function query(): Builder
    {
        return new Builder(self::$connection);
    }
}
