<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler;

use Nowo\CurrencyKitBundle\Application\CurrencyConverterInterface;
use Nowo\CurrencyKitBundle\Application\NullResolvedRateCache;
use Nowo\CurrencyKitBundle\Infrastructure\Cache\Psr6ResolvedRateCache;
use Nowo\CurrencyKitBundle\Infrastructure\Support\PsrLogAuditSink;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\DependencyInjection\Requirement;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\DependencyInjection\ServiceDefinitions;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\AllowAllCurrencyKitAccessChecker;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\ConfigurableCurrencyKitAccessChecker;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Twig\ReferenceMoneyExtension;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;

use function extension_loaded;
use function is_bool;
use function is_string;

/**
 * Wires the optional collaborators once every bundle has registered its services: logger, HTTP client, outbound URL
 * guard, shared cache pool, audit sink, access checker and the Twig extension (needs ext-intl).
 */
final class CurrencyKitPortsPass implements CompilerPassInterface
{
    /**
     * {@inheritDoc}
     */
    public function process(ContainerBuilder $container): void
    {
        $p = ServiceDefinitions::P;
        /** @var array{cache: array{pool: ?string, ttl: int}, twig: array{enabled: bool, on_missing_rate: string, date_format: string}, scheduler: array{enabled: bool}, sync: array{times: list<string>, catch_up_time: string}, security: array{access_roles: list<string>, access_checker: ?string, dashboard_roles: list<string>, manage_roles: list<string>}, audit: array{sink: ?string}} $config */
        $config = $container->getParameter($p . 'config');

        // FrameworkBundle always provides "logger" (its LoggerPass registers a default one without Monolog).
        $container->setAlias($p . 'logger', 'logger');
        if ($container->has('http_client')) {
            $container->setAlias($p . 'http_client', 'http_client');
        } else {
            $container->setDefinition($p . 'http_client', (new Definition('Symfony\Contracts\HttpClient\HttpClientInterface'))->setFactory([HttpClient::class, 'create']));
        }
        if ($container->has(OutboundUrlGuard::class)) {
            $container->setAlias($p . 'url_guard', OutboundUrlGuard::class);
        } else {
            $container->setDefinition($p . 'url_guard', new Definition(OutboundUrlGuard::class));
        }

        $pool = $config['cache']['pool'];
        $container->setDefinition($p . 'resolved_rate_cache', is_string($pool)
            ? (new Definition(Psr6ResolvedRateCache::class))->setArguments([new Reference($pool), $config['cache']['ttl']])
            : new Definition(NullResolvedRateCache::class));

        $sink = $config['audit']['sink'];
        if (is_string($sink)) {
            $container->setAlias($p . 'audit_sink', $sink);
        } else {
            $container->setDefinition($p . 'audit_sink', (new Definition(PsrLogAuditSink::class))->setArguments([new Reference($p . 'logger')]));
        }

        $checker = $config['security']['access_checker'];
        if ($checker === 'allow_all') {
            $container->setDefinition($p . 'access_checker', new Definition(AllowAllCurrencyKitAccessChecker::class));
        } elseif (is_string($checker)) {
            $container->setAlias($p . 'access_checker', $checker);
        } else {
            $container->setDefinition($p . 'access_checker', (new Definition(ConfigurableCurrencyKitAccessChecker::class))->setArguments([
                $config['security']['access_roles'],
                $config['security']['dashboard_roles'],
                $config['security']['manage_roles'],
                new Reference('security.role_hierarchy', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]));
        }

        $this->twig($container, $config['twig']);
    }

    /**
     * The Twig extension when Twig is present and `twig.enabled` (ext-intl required).
     *
     * @param array{enabled: bool, on_missing_rate: string, date_format: string} $twig
     */
    private function twig(ContainerBuilder $container, array $twig): void
    {
        if (!$twig['enabled'] || !$container->has('twig') || !$container->has('translator')) {
            return;
        }
        Requirement::check(extension_loaded('intl'), 'nowo_currency_kit.twig.enabled needs ext-intl (or set twig.enabled: false).');
        $debug = $container->hasParameter('kernel.debug') ? $container->getParameter('kernel.debug') : false;
        $container->setDefinition(ReferenceMoneyExtension::class, (new Definition(ReferenceMoneyExtension::class))
            ->setArguments([
                new Reference(CurrencyConverterInterface::class),
                new Reference('translator'),
                new Reference('request_stack'),
                new Reference(ServiceDefinitions::P . 'logger'),
                $twig['on_missing_rate'],
                $twig['date_format'],
                is_bool($debug) && $debug,
            ])
            ->addTag('twig.extension'));
    }
}
