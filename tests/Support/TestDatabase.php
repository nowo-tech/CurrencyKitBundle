<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Support;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\CurrencyKitBundle\Infrastructure\Doctrine\SchemaMetadataListener;

use function dirname;
use function getenv;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function sys_get_temp_dir;
use function unlink;

use const PHP_VERSION_ID;

/**
 * The test database: `NOWO_TEST_DATABASE_URL` (CI matrix: MySQL 8.4, MariaDB 11.4, PostgreSQL 16) or a SQLite file.
 * Builds connections and an entity manager with the bundle's mapping (XML or attributes) and the schema listener.
 */
final class TestDatabase
{
    /** Entity namespace. */
    public const ENTITIES = 'Nowo\CurrencyKitBundle\Infrastructure\Doctrine\Entity';

    /**
     * The database URL.
     */
    public static function url(): string
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');
        if (is_string($url) && $url !== '') {
            return $url;
        }
        $dir = sys_get_temp_dir() . '/nowo-currency-kit-tests';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        return 'sqlite:///' . $dir . '/test.sqlite';
    }

    /**
     * Whether the database is a real server (not SQLite).
     */
    public static function isServer(): bool
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');

        return is_string($url) && $url !== '';
    }

    /**
     * A new connection.
     */
    public static function connection(?string $url = null): Connection
    {
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite']))->parse($url ?? self::url());

        return DriverManager::getConnection($params);
    }

    /**
     * An entity manager on the connection with the bundle's mapping.
     */
    public static function entityManager(Connection $connection, string $mapping = 'xml', string $prefix = 'nowo_'): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl($mapping === 'xml'
            ? new SimplifiedXmlDriver([dirname(__DIR__, 2) . '/src/Resources/config/doctrine' => self::ENTITIES])
            : new AttributeDriver([dirname(__DIR__, 2) . '/src/Infrastructure/Doctrine/Entity']));
        $config->setProxyDir(sys_get_temp_dir() . '/nowo-currency-kit-proxies');
        $config->setProxyNamespace('NowoCurrencyKitProxies');
        $config->setAutoGenerateProxyClasses(true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $events = new EventManager();
        $events->addEventListener([Events::loadClassMetadata], new SchemaMetadataListener($prefix));

        return new EntityManager($connection, $config, $events);
    }

    /**
     * Drops and creates the bundle's tables.
     */
    public static function reset(EntityManager $entityManager): void
    {
        $tool    = new SchemaTool($entityManager);
        $classes = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($classes);
        $tool->createSchema($classes);
    }

    /**
     * A fresh database: connection, entity manager, empty tables.
     *
     * @return array{0: Connection, 1: EntityManager}
     */
    public static function fresh(string $mapping = 'xml', string $prefix = 'nowo_'): array
    {
        $connection = self::connection();
        $manager    = self::entityManager($connection, $mapping, $prefix);
        self::reset($manager);

        return [$connection, $manager];
    }

    /**
     * The platform family: `mysql`, `postgresql` or `sqlite`.
     */
    public static function family(Connection $connection): string
    {
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof PostgreSQLPlatform    => 'postgresql',
            default                                    => 'sqlite',
        };
    }

    /**
     * Removes the SQLite file (between test classes).
     */
    public static function forgetSqlite(): void
    {
        $file = sys_get_temp_dir() . '/nowo-currency-kit-tests/test.sqlite';
        if (!self::isServer() && is_file($file)) {
            unlink($file);
        }
    }
}
