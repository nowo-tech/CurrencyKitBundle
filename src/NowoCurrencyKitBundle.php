<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle;

use Nowo\CurrencyKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler\CurrencyKitPortsPass;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\DependencyInjection\ConfigurationTree;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\DependencyInjection\ServiceDefinitions;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Currency conversion at reference rates for Symfony (ECB euro reference rates by default): rates stored by date in
 * the application database, decimal-string amounts, cross rates through the euro, manual rates, console sync with a
 * Scheduler bridge and a Twig filter. Every result is a reference value, never a payment or accounting rate.
 *
 * 0.1 ships the core, the console, the Scheduler bridge and the Twig filter; the UiKit admin UI arrives in 0.2.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoCurrencyKitBundle extends AbstractBundle
{
    /** Configuration root and service / parameter prefix. */
    public const ALIAS = 'nowo_currency_kit';

    /** Translation domain. */
    public const TRANSLATION_DOMAIN = 'NowoCurrencyKitBundle';

    /** Doctrine mapping name. */
    public const MAPPING_NAME = 'NowoCurrencyKitBundle';

    protected string $extensionAlias = self::ALIAS;

    /**
     * The bundle's resources live in `src/Resources`.
     */
    public function getPath(): string
    {
        return __DIR__;
    }

    /**
     * Adds the ports pass.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // Before the Twig, Messenger and console passes (priority 0) collect their tags.
        $container->addCompilerPass(new CurrencyKitPortsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }

    /**
     * The strict configuration tree (contract section 11).
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        ConfigurationTree::define($definition->rootNode());
    }

    /**
     * Registers the mapping (XML by default, attributes on request) on the configured entity manager, so schema tools
     * and a host's drift gate see the bundle's tables.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('doctrine')) {
            return;
        }
        $manager = 'default';
        $mapping = 'xml';
        foreach ($builder->getExtensionConfig(self::ALIAS) as $config) {
            $doctrine = is_array($config) && is_array($config['doctrine'] ?? null) ? $config['doctrine'] : [];
            if (array_key_exists('entity_manager', $doctrine)) {
                $manager = is_string($doctrine['entity_manager']) && $doctrine['entity_manager'] !== '' ? $doctrine['entity_manager'] : 'default';
            }
            if (($doctrine['mapping'] ?? null) === 'attribute') {
                $mapping = 'attribute';
            }
        }
        $builder->prependExtensionConfig('doctrine', ['orm' => ['entity_managers' => [$manager => ['mappings' => [self::MAPPING_NAME => [
            'type'      => $mapping,
            'dir'       => $mapping === 'xml' ? __DIR__ . '/Resources/config/doctrine' : __DIR__ . '/Infrastructure/Doctrine/Entity',
            'prefix'    => 'Nowo\CurrencyKitBundle\Infrastructure\Doctrine\Entity',
            'is_bundle' => false,
        ]]]]]]);
    }

    /**
     * Registers the services.
     *
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        ServiceDefinitions::register($builder, $config);
    }
}
