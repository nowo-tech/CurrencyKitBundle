<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\CurrencyKitBundle\Application\SchemaCheckerInterface;

use function array_values;
use function str_starts_with;

/**
 * Compares the live tables with the bundle's mapping (contract section 6.1): columns, types, collations and indexes.
 * Reads metadata and the database schema only; never flushes, clears or resets the entity manager.
 */
final readonly class SchemaChecker implements SchemaCheckerInterface
{
    /** Namespace of the bundle's entities. */
    private const ENTITY_NAMESPACE = 'Nowo\\CurrencyKitBundle\\Infrastructure\\Doctrine\\Entity\\';

    /**
     * @param EntityManagerInterface $entityManager the manager whose connection the bundle uses
     */
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function diff(): array
    {
        $expected   = (new SchemaTool($this->entityManager))->getSchemaFromMetadata($this->metadata());
        $connection = $this->entityManager->getConnection();
        $manager    = $connection->createSchemaManager();
        $tables     = [];
        foreach ($expected->getTables() as $table) {
            if ($manager->tablesExist([$table->getName()])) {
                $tables[] = $manager->introspectTable($table->getName());
            }
        }

        return array_values($connection->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas(new Schema($tables), $expected)));
    }

    /**
     * The reference DDL of the bundle's tables on the connection's platform (no connection needed when the server
     * version is configured).
     *
     * @return list<string>
     */
    public function createSql(): array
    {
        return array_values((new SchemaTool($this->entityManager))->getCreateSchemaSql($this->metadata()));
    }

    /**
     * The bundle's mapped classes.
     *
     * @return list<ClassMetadata<object>>
     */
    private function metadata(): array
    {
        $classes = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (str_starts_with($metadata->getName(), self::ENTITY_NAMESPACE)) {
                $classes[] = $metadata;
            }
        }

        return $classes;
    }
}
