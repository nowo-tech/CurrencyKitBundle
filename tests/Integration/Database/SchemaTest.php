<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ServerVersionProvider;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\CurrencyKitBundle\Infrastructure\Doctrine\SchemaChecker;
use Nowo\CurrencyKitBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function fwrite;
use function getenv;
use function implode;
use function interface_exists;
use function str_contains;

use const STDERR;

/**
 * The mapping (contract section 6): XML and attributes give the same schema, the live tables match it (the host's
 * drift gate sees no diff), identifier columns are NO PAD binary on MySQL / MariaDB, and the reference DDL in
 * docs/UPGRADING.md is the one the mapping generates.
 */
final class SchemaTest extends TestCase
{
    public function testXmlAndAttributeMappingsGiveTheSameSchema(): void
    {
        $connection = TestDatabase::connection();
        $xml        = TestDatabase::entityManager($connection, 'xml');
        $attribute  = TestDatabase::entityManager($connection, 'attribute');
        $a          = (new SchemaTool($xml))->getSchemaFromMetadata($xml->getMetadataFactory()->getAllMetadata());
        $b          = (new SchemaTool($attribute))->getSchemaFromMetadata($attribute->getMetadataFactory()->getAllMetadata());
        $comparator = $connection->createSchemaManager()->createComparator();
        self::assertSame([], $connection->getDatabasePlatform()->getAlterSchemaSQL($comparator->compareSchemas($a, $b)));
        self::assertSame([], $connection->getDatabasePlatform()->getAlterSchemaSQL($comparator->compareSchemas($b, $a)));
        self::assertCount(5, $xml->getMetadataFactory()->getAllMetadata());
    }

    public function testLiveTablesMatchTheMappingAndPrefix(): void
    {
        [$connection, $manager] = TestDatabase::fresh('xml', 'acme_');
        self::assertSame([], (new SchemaChecker($manager))->diff(), 'no drift after creating the tables');
        self::assertTrue($connection->createSchemaManager()->tablesExist(['acme_currency_rate']));
        $connection->executeStatement('DROP TABLE acme_currency_rate_change');
        self::assertNotSame([], (new SchemaChecker($manager))->diff());
        TestDatabase::reset($manager);
        TestDatabase::reset(TestDatabase::entityManager($connection));
    }

    public function testIdentifierColumnsAreExactOnMysqlAndMariadb(): void
    {
        self::requireDbal4();
        foreach (['8.4.0' => 'utf8mb4_0900_bin', '11.4.0-MariaDB' => 'utf8mb4_nopad_bin'] as $version => $collation) {
            $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => $version, 'host' => 'unused.invalid']);
            $sql        = implode("\n", (new SchemaChecker(TestDatabase::entityManager($connection)))->createSql());
            self::assertStringContainsString('currency CHAR(3) CHARACTER SET utf8mb4 NOT NULL COLLATE `' . $collation . '`', $sql);
            self::assertStringContainsString('UNIQUE INDEX nowo_currency_rate_source_date_currency_uniq', $sql);
        }
    }

    public function testReferenceDdlInUpgradingIsCurrent(): void
    {
        self::requireDbal4();
        $docs      = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/UPGRADING.md');
        $all       = [];
        $platforms = ['mysql' => ['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0'], 'mariadb' => ['driver' => 'pdo_mysql', 'serverVersion' => '11.4.0-MariaDB'], 'sqlite' => ['driver' => 'pdo_sqlite', 'memory' => true]];
        foreach ($platforms as $name => $params) {
            $connection = DriverManager::getConnection($params + ['host' => 'unused.invalid']);
            $statements = (new SchemaChecker(TestDatabase::entityManager($connection)))->createSql();
            if (getenv('CYK_PRINT_DDL') === '1') {
                fwrite(STDERR, "\n-- " . $name . "\n" . implode(";\n", $statements) . ";\n");
            }
            $all[$name] = $statements;
        }
        // PostgreSQL's schema tool reads the search path: its DDL is checked on the CI PostgreSQL service.
        $server = TestDatabase::connection();
        if (TestDatabase::family($server) === 'postgresql') {
            $all['postgresql'] = (new SchemaChecker(TestDatabase::entityManager($server)))->createSql();
            if (getenv('CYK_PRINT_DDL') === '1') {
                fwrite(STDERR, "\n-- postgresql\n" . implode(";\n", $all['postgresql']) . ";\n");
            }
        }
        foreach ($all as $name => $statements) {
            foreach ($statements as $statement) {
                self::assertTrue(str_contains($docs, $statement . ';'), $name . ': docs/UPGRADING.md misses ' . $statement);
            }
        }
    }

    /**
     * The offline platform DDL needs DBAL 4 (DBAL 3 connects to build a schema manager); the DBAL 4 cells check it.
     */
    private static function requireDbal4(): void
    {
        // @phpstan-ignore-next-line (always true with the DBAL 4 used for analysis; false on the DBAL 3 CI step)
        if (!interface_exists(ServerVersionProvider::class)) {
            self::markTestSkipped('Reference DDL is checked on DBAL 4.');
        }
    }
}
