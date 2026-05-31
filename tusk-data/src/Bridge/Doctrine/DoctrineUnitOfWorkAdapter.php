<?php

namespace Tusk\Data\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Tusk\Contracts\Attributes\Service;
use Tusk\Contracts\Data\UnitOfWorkInterface;

/**
 * Adapter that bridges Tusk's UnitOfWorkInterface to Doctrine's EntityManager.
 * Marked as request scoped so that the container will naturally instantiate 
 * a new UnitOfWork for each request cycle. Wait, we need to ensure the 
 * underlying EM is also clear. We can call EM->clear() in the clear() method.
 */
#[Service(scope: 'request')]
class DoctrineUnitOfWorkAdapter implements UnitOfWorkInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    public function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
    }

    public function remove(object $entity): void
    {
        $this->entityManager->remove($entity);
    }

    public function commit(): void
    {
        $this->entityManager->flush();
    }

    public function clear(): void
    {
        $this->entityManager->clear();
    }
}
