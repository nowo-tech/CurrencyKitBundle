<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Infrastructure;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\CurrencyKitBundle\Infrastructure\Doctrine\SchemaMetadataListener;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\DependencyInjection\Requirement;
use Nowo\CurrencyKitBundle\NowoCurrencyKitBundle;
use Nowo\CurrencyKitBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class BundleAndListenerTest extends TestCase
{
    public function testForeignEntitiesAreLeftAlone(): void
    {
        $manager  = TestDatabase::entityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $metadata = new ClassMetadata(stdClass::class);
        $metadata->setPrimaryTable(['name' => 'host_table']);
        (new SchemaMetadataListener('nowo_'))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $manager));
        self::assertSame('host_table', $metadata->getTableName());
    }

    public function testRequirement(): void
    {
        Requirement::check(true, 'met');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs ext-intl');
        Requirement::check(false, 'needs ext-intl');
    }

    public function testNoMappingWithoutDoctrine(): void
    {
        $builder      = new ContainerBuilder();
        $instanceof   = [];
        $configurator = new ContainerConfigurator($builder, new PhpFileLoader($builder, new FileLocator(__DIR__)), $instanceof, __DIR__, __FILE__);
        (new NowoCurrencyKitBundle())->prependExtension($configurator, $builder);
        self::assertSame([], $builder->getExtensionConfig('doctrine'));

        // With DoctrineBundle but no ORM configuration yet, the mapping goes to DoctrineBundle's "default" manager.
        $builder->registerExtension(new DoctrineExtension());
        (new NowoCurrencyKitBundle())->prependExtension($configurator, $builder);
        /** @var array{orm: array{entity_managers: array<string, mixed>}} $config */
        $config = $builder->getExtensionConfig('doctrine')[0];
        self::assertArrayHasKey('default', $config['orm']['entity_managers']);
    }
}
