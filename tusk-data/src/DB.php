<?php

namespace Tusk\Data;

use Doctrine\DBAL\Connection;

class DB
{
    protected static Connection $connection;

    public static function setConnection(Connection $connection): void
    {
        self::$connection = $connection;
    }

    public static function connection(): Connection
    {
        return self::$connection;
    }
}
