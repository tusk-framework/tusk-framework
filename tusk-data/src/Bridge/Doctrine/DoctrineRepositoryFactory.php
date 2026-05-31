<?php

namespace Tusk\Data\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Tusk\Contracts\Attributes\Service;

/**
 * Factory to dynamically create Repository adapters for entities.
 */
#[Service(scope: 'request')]
class DoctrineRepositoryFactory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DoctrineUnitOfWorkAdapter $uow
    ) {}

    /**
     * @param class-string $entityClass
     */
    public function createFor(string $entityClass): DoctrineRepositoryAdapter
    {
        $doctrineRepository = $this->entityManager->getRepository($entityClass);
        
        return new DoctrineRepositoryAdapter($doctrineRepository, $this->uow);
    }
}
