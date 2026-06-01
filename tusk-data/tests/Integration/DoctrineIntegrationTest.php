<?php

namespace Tusk\Data\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Tusk\Config\Env;
use Tusk\Data\Bridge\Doctrine\DoctrineUnitOfWorkAdapter;
use Tusk\Data\Bridge\Doctrine\EntityManagerFactory;

#[Entity]
#[Table(name: "test_users")]
class TestUser
{
    #[Id, Column(type: "integer"), GeneratedValue]
    public ?int $id = null;

    #[Column(type: "string")]
    public string $name;
}

/**
 * @requires extension pdo_sqlite
 */
class DoctrineIntegrationTest extends TestCase
{
    private EntityManagerInterface $em;
    private DoctrineUnitOfWorkAdapter $uow;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Force SQLite in-memory for tests
        $_ENV['DB_DRIVER'] = 'pdo_sqlite';
        $_ENV['DB_PATH'] = ':memory:';
        $_ENV['DB_ENTITY_PATHS'] = __DIR__;
        
        $factory = new EntityManagerFactory();
        $this->em = $factory();
        
        // Use schema tool to create in-memory tables
        $schemaTool = new SchemaTool($this->em);
        $classes = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($classes);
        
        $this->uow = new DoctrineUnitOfWorkAdapter($this->em);
    }

    public function testCanPersistAndRetrieveEntity(): void
    {
        $user = new TestUser();
        $user->name = "Jackson";
        
        $this->uow->persist($user);
        $this->uow->commit();
        
        $this->assertNotNull($user->id);
        
        // Clear to avoid identity map caching and test retrieval
        $this->uow->clear();
        
        $retrieved = $this->em->find(TestUser::class, $user->id);
        $this->assertNotNull($retrieved);
        $this->assertEquals("Jackson", $retrieved->name);
    }
}
