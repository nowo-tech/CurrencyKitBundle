<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Integration\Kernel;

use Nowo\CurrencyKitBundle\Tests\Support\FakeDns;
use Nowo\OutboundUrlGuardBundle\Dns\HostnameDnsLookup;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Test overrides applied after every extension: the MockClock as `clock`, FakeDns as the guard's DNS seam, and the
 * bundle's public test handles.
 */
final class TestOverridesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->setAlias('clock', 'test.mock_clock')->setPublic(true);
        if ($container->hasDefinition(HostnameDnsLookup::class)) {
            $container->setDefinition(HostnameDnsLookup::class, new Definition(FakeDns::class));
        }
        foreach (['nowo_currency_kit.connection', 'nowo_currency_kit.entity_manager', 'nowo_currency_kit.access_checker', 'nowo_currency_kit.audit_sink', 'nowo_currency_kit.resolved_rate_cache', 'nowo_currency_kit.http_client', 'nowo_currency_kit.url_guard', 'nowo_currency_kit.logger', 'event_dispatcher'] as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            } elseif ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            }
        }
    }
}
