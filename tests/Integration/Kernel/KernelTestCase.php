<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Integration\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;

/**
 * Boots {@see TestKernel} with a configuration, creates the bundle's tables and runs commands.
 */
abstract class KernelTestCase extends TestCase
{
    protected ?TestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-currency-kit-kernel');
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, bool> $features
     * @param 'ddd'|'named'|'standard' $host
     */
    protected function boot(array $config = [], array $features = [], string $host = 'standard', bool $schema = true, string $now = '2026-11-19 17:00:00'): ContainerInterface
    {
        $this->kernel?->shutdown();
        $this->kernel = new TestKernel($config, $features, $host, $now);
        $this->kernel->boot();
        if ($schema) {
            $manager = $this->container()->get('nowo_currency_kit.entity_manager');
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            $tool    = new SchemaTool($manager);
            $classes = $manager->getMetadataFactory()->getAllMetadata();
            $tool->dropSchema($classes);
            $tool->createSchema($classes);
        }

        return $this->container();
    }

    protected function container(): ContainerInterface
    {
        self::assertNotNull($this->kernel);

        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function command(string $name, array $input = []): CommandTester
    {
        self::assertNotNull($this->kernel);
        $application = new Application($this->kernel);
        $tester      = new CommandTester($application->find($name));
        $tester->execute($input);

        return $tester;
    }

    protected function http(): TestHttpFactory
    {
        $factory = $this->container()->get('test.http_factory');
        self::assertInstanceOf(TestHttpFactory::class, $factory);

        return $factory;
    }
}
