<?php

namespace Tusk\Data\Bridge\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Tusk\Contracts\Attributes\Factory;

#[Factory(provides: Connection::class, scope: 'singleton')]
class DbalConnectionFactory
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * @return Connection
     */
    public function __invoke(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
