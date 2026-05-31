<?php

namespace Tusk\Data\Bridge\Doctrine;

use Doctrine\ORM\EntityRepository;
use Tusk\Contracts\Data\RepositoryInterface;

/**
 * Adapter to wrap a Doctrine EntityRepository into Tusk's RepositoryInterface.
 */
class DoctrineRepositoryAdapter implements RepositoryInterface
{
    public function __construct(
        private EntityRepository $doctrineRepository,
        private DoctrineUnitOfWorkAdapter $uow
    ) {}

    public function find(mixed $id): ?object
    {
        return $this->doctrineRepository->find($id);
    }

    public function save(object $entity): void
    {
        $this->uow->persist($entity);
        $this->uow->commit();
    }

    public function remove(object $entity): void
    {
        $this->uow->remove($entity);
        $this->uow->commit();
    }
    
    /**
     * Expose native repository for advanced Doctrine features
     */
    public function getNativeRepository(): EntityRepository
    {
        return $this->doctrineRepository;
    }
}
