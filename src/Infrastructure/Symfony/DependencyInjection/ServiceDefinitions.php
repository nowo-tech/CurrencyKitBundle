<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\DependencyInjection;

use Composer\InstalledVersions;
use DateTimeZone;
use Nowo\CurrencyKitBundle\Application\BatchValidator;
use Nowo\CurrencyKitBundle\Application\BundledCurrencyCatalogue;
use Nowo\CurrencyKitBundle\Application\CurrencyConverter;
use Nowo\CurrencyKitBundle\Application\CurrencyConverterInterface;
use Nowo\CurrencyKitBundle\Application\CurrencyKitSettings;
use Nowo\CurrencyKitBundle\Application\ManualRateService;
use Nowo\CurrencyKitBundle\Application\ManualRateServiceInterface;
use Nowo\CurrencyKitBundle\Application\RateMaintenance;
use Nowo\CurrencyKitBundle\Application\RateMemo;
use Nowo\CurrencyKitBundle\Application\RateResolver;
use Nowo\CurrencyKitBundle\Application\RateSourceRegistry;
use Nowo\CurrencyKitBundle\Application\RateSynchronizer;
use Nowo\CurrencyKitBundle\Application\RateSynchronizerInterface;
use Nowo\CurrencyKitBundle\Application\ReferenceRateReader;
use Nowo\CurrencyKitBundle\Application\ReferenceRateReaderInterface;
use Nowo\CurrencyKitBundle\Application\TargetCalendar;
use Nowo\CurrencyKitBundle\Bridge\Scheduler\CurrencyKitScheduleProvider;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyCatalogueInterface;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitActorResolverInterface;
use Nowo\CurrencyKitBundle\Domain\Port\IdGeneratorInterface;
use Nowo\CurrencyKitBundle\Domain\Port\OutboundFetcherInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RateFileReaderInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RateStoreInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RunLockInterface;
use Nowo\CurrencyKitBundle\Domain\Port\TargetCalendarInterface;
use Nowo\CurrencyKitBundle\Domain\Port\WriteRunnerInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceLabel;
use Nowo\CurrencyKitBundle\Infrastructure\Dbal\ConnectionOnlyRegistry;
use Nowo\CurrencyKitBundle\Infrastructure\Dbal\DatabaseRunLock;
use Nowo\CurrencyKitBundle\Infrastructure\Dbal\DbalRateStore;
use Nowo\CurrencyKitBundle\Infrastructure\Dbal\DbalWriteRunner;
use Nowo\CurrencyKitBundle\Infrastructure\Dbal\TableNames;
use Nowo\CurrencyKitBundle\Infrastructure\Doctrine\SchemaChecker;
use Nowo\CurrencyKitBundle\Infrastructure\Doctrine\SchemaMetadataListener;
use Nowo\CurrencyKitBundle\Infrastructure\Http\GuardedHttpFetcher;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Csv\CsvRateParser;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Ecb\EcbXmlParser;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Ecb\EcbXmlSource;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Frankfurter\FrankfurterCompatibleSource;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Frankfurter\FrankfurterJsonParser;
use Nowo\CurrencyKitBundle\Infrastructure\Source\LocalRateFileReader;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Manual\ManualRateSource;
use Nowo\CurrencyKitBundle\Infrastructure\Support\UuidV7IdGenerator;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\ActorForgetCommand;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\CommandSupport;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\ConvertCommand;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\ManualEndCommand;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\ManualListCommand;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\ManualSetCommand;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\PruneCommand;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\RatesBackfillCommand;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\RatesChangesCommand;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\RatesImportCommand;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\RatesShowCommand;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\RatesSyncCommand;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\SchemaCheckCommand;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\EventListener\RateMemoResetListener;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Messenger\SyncReferenceRatesHandler;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\CurrencyKitGuard;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\SecurityActorResolver;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

use function class_exists;
use function interface_exists;
use function is_string;
use function sprintf;

/**
 * Registers the bundle's services from the processed configuration (contract section 12). Optional collaborators
 * (logger, HTTP client, URL guard, cache pool, Twig, Scheduler, security, Messenger) are wired by
 * {@see Compiler\CurrencyKitPortsPass} once every bundle has registered its services.
 */
final class ServiceDefinitions
{
    /** Service and parameter prefix. */
    public const P = 'nowo_currency_kit.';

    /** Tag of the rate sources. */
    public const SOURCE_TAG = 'nowo_currency_kit.rate_source';

    /**
     * Registers the services.
     *
     * @param array<string, mixed> $config the processed configuration
     */
    public static function register(ContainerBuilder $container, array $config): void
    {
        /** @var array{doctrine: array{entity_manager: ?string, table_prefix: string, mapping: string}, sources: array{ecb: array{enabled: bool, label: string, daily_url: string, last_90_days_url: string, history_url: string}, frankfurter: array{enabled: bool, key: string, label: string, base_uri: ?string, timezone: string, allow_private_network: bool, rebase: bool}, manual: array{label: string}}, conversion: array{source: string, max_lookback_days: int, max_staleness_days: int, on_stale: mixed, rounding_mode: string}, sync: array{initial: string, times: list<string>, catch_up_time: string, on_changed_value: string, max_daily_change_percent: int, lock: array{store: string, ttl_seconds: int}}, backfill: array{max_days: int, chunk_days: int}, outbound: array{enabled: mixed, timeout: int, max_duration: int, retries: int, contact_url: string, max_bytes: array{daily: int, last_90_days: int, history: int, api: int}}, manual: array{max_deviation_percent: int, allow_past: bool}, scheduler: array{enabled: bool, transport: ?string}, cache: array{pool: ?string, ttl: int}, twig: array{enabled: bool, on_missing_rate: string, date_format: string}, ui: array{enabled: bool, path_prefix: string}, security: array{access_roles: list<string>, access_checker: ?string, allow_unauthenticated: bool, dashboard_roles: list<string>, manage_roles: list<string>}, audit: array{sink: ?string}} $config */
        $p = self::P;

        $container->setParameter($p . 'ui_path_prefix', $config['ui']['path_prefix']);
        $container->setParameter($p . 'config', $config);

        $settings = (new Definition(CurrencyKitSettings::class))
            ->setFactory([CurrencyKitSettings::class, 'fromConfig'])
            ->setArguments([
                $config['conversion'],
                [
                    'initial'                  => $config['sync']['initial'],
                    'on_changed_value'         => $config['sync']['on_changed_value'],
                    'max_daily_change_percent' => $config['sync']['max_daily_change_percent'],
                    'lock_ttl_seconds'         => $config['sync']['lock']['ttl_seconds'],
                    'backfill_max_days'        => $config['backfill']['max_days'],
                    'backfill_chunk_days'      => $config['backfill']['chunk_days'],
                ],
                ['enabled' => $config['outbound']['enabled'], 'retries' => $config['outbound']['retries']],
                $config['manual'],
            ]);
        $container->setDefinition(CurrencyKitSettings::class, $settings)->setPublic(true);

        $memo = (new Definition(RateMemo::class))->addTag('kernel.reset', ['method' => 'reset']);
        $container->setDefinition(RateMemo::class, $memo);
        $container->setDefinition(RateMemoResetListener::class, (new Definition(RateMemoResetListener::class))
            ->setArguments([new Reference(RateMemo::class)])
            ->addTag('kernel.event_listener', ['event' => 'kernel.request', 'priority' => 4096])
            ->addTag('kernel.event_listener', ['event' => 'kernel.finish_request', 'priority' => -4096])
            ->addTag('kernel.event_listener', ['event' => 'kernel.terminate', 'priority' => -4096])
            ->addTag('kernel.event_listener', ['event' => 'console.terminate', 'priority' => -4096])
            ->addTag('kernel.event_listener', ['event' => 'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent']));

        $container->setDefinition(BundledCurrencyCatalogue::class, new Definition(BundledCurrencyCatalogue::class));
        $container->setAlias(CurrencyCatalogueInterface::class, BundledCurrencyCatalogue::class)->setPublic(true);
        $container->setDefinition(TargetCalendar::class, new Definition(TargetCalendar::class));
        $container->setAlias(TargetCalendarInterface::class, TargetCalendar::class);
        $container->setDefinition(UuidV7IdGenerator::class, new Definition(UuidV7IdGenerator::class));
        $container->setAlias(IdGeneratorInterface::class, UuidV7IdGenerator::class);
        $container->setDefinition(SecurityActorResolver::class, (new Definition(SecurityActorResolver::class))
            ->setArguments([new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE)]));
        $container->setAlias(CurrencyKitActorResolverInterface::class, SecurityActorResolver::class);

        self::storage($container, $config['doctrine']);
        self::sources($container, $config);

        $container->setDefinition(RateSourceRegistry::class, (new Definition(RateSourceRegistry::class))->setArguments([new TaggedIteratorArgument(self::SOURCE_TAG)]));
        $container->setDefinition(RateResolver::class, (new Definition(RateResolver::class))->setArguments([
            new Reference(RateStoreInterface::class),
            new Reference(RateSourceRegistry::class),
            new Reference(CurrencyKitSettings::class),
            new Reference(RateMemo::class),
            new Reference($p . 'resolved_rate_cache'),
            new Reference('clock'),
            new Reference($p . 'logger'),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
        $container->setDefinition(CurrencyConverter::class, (new Definition(CurrencyConverter::class))->setArguments([
            new Reference(RateResolver::class),
            new Reference(CurrencyCatalogueInterface::class),
            new Reference(CurrencyKitSettings::class),
        ]));
        $container->setAlias(CurrencyConverterInterface::class, CurrencyConverter::class)->setPublic(true);
        $container->setDefinition(BatchValidator::class, (new Definition(BatchValidator::class))->setArguments([new Reference(CurrencyCatalogueInterface::class), new Reference(RateStoreInterface::class)]));
        $container->setDefinition(RateSynchronizer::class, (new Definition(RateSynchronizer::class))->setArguments([
            new Reference(RateSourceRegistry::class),
            new Reference(RateStoreInterface::class),
            new Reference(WriteRunnerInterface::class),
            new Reference(RunLockInterface::class),
            new Reference(BatchValidator::class),
            new Reference(TargetCalendarInterface::class),
            new Reference(RateFileReaderInterface::class),
            new Reference(IdGeneratorInterface::class),
            new Reference($p . 'audit_sink'),
            new Reference($p . 'resolved_rate_cache'),
            new Reference(RateMemo::class),
            new Reference(CurrencyKitSettings::class),
            new Reference('clock'),
            new Reference($p . 'logger'),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
        $container->setAlias(RateSynchronizerInterface::class, RateSynchronizer::class)->setPublic(true);
        $container->setDefinition(ManualRateService::class, (new Definition(ManualRateService::class))->setArguments([
            new Reference(RateStoreInterface::class),
            new Reference(WriteRunnerInterface::class),
            new Reference(IdGeneratorInterface::class),
            new Reference(CurrencyKitActorResolverInterface::class),
            new Reference($p . 'audit_sink'),
            new Reference($p . 'resolved_rate_cache'),
            new Reference(RateMemo::class),
            new Reference(RateResolver::class),
            new Reference(CurrencyKitSettings::class),
            new Reference('clock'),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
        $container->setAlias(ManualRateServiceInterface::class, ManualRateService::class)->setPublic(true);
        $container->setDefinition(ReferenceRateReader::class, (new Definition(ReferenceRateReader::class))->setArguments([new Reference(RateStoreInterface::class), new Reference(CurrencyKitSettings::class)]));
        $container->setAlias(ReferenceRateReaderInterface::class, ReferenceRateReader::class)->setPublic(true);
        $container->setDefinition(RateMaintenance::class, (new Definition(RateMaintenance::class))->setArguments([
            new Reference(RateStoreInterface::class),
            new Reference(WriteRunnerInterface::class),
            new Reference(IdGeneratorInterface::class),
            new Reference($p . 'audit_sink'),
            new Reference($p . 'resolved_rate_cache'),
            new Reference(RateMemo::class),
            new Reference(RateResolver::class),
            new Reference(CurrencyKitSettings::class),
            new Reference('clock'),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true);

        $container->setDefinition(CurrencyKitGuard::class, (new Definition(CurrencyKitGuard::class))->setArguments([
            new Reference($p . 'access_checker'),
            new Reference('event_dispatcher'),
            $config['security']['allow_unauthenticated'],
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true);

        self::commands($container);
        self::messaging($container, $config['scheduler']['enabled'], $config['sync']['times'], $config['sync']['catch_up_time']);
    }

    /**
     * The Messenger handler (when Messenger is installed) and the Scheduler bridge (`scheduler.enabled`).
     *
     * @param list<string> $times
     *
     * @throws InvalidArgumentException when the scheduler is enabled without symfony/scheduler
     */
    private static function messaging(ContainerBuilder $container, bool $scheduler, array $times, string $catchUp): void
    {
        if (class_exists(AsMessageHandler::class)) {
            $container->setDefinition(SyncReferenceRatesHandler::class, (new Definition(SyncReferenceRatesHandler::class))
                ->setArguments([new Reference(RateSynchronizerInterface::class), new Reference(CurrencyKitSettings::class), new Reference(self::P . 'logger')])
                // #[AsMessageHandler] on the class: FrameworkBundle's autoconfiguration adds the Messenger tag (REQ-MSG-001).
                ->setAutoconfigured(true));
        }
        if (!$scheduler) {
            return;
        }
        Requirement::check(interface_exists(ScheduleProviderInterface::class), 'nowo_currency_kit.scheduler.enabled needs symfony/scheduler (and dragonmantank/cron-expression).');
        $container->setDefinition(CurrencyKitScheduleProvider::class, (new Definition(CurrencyKitScheduleProvider::class))
            ->setArguments([new Reference(ReferenceRateReaderInterface::class), new Reference(CurrencyKitSettings::class), $times, $catchUp])
            ->addTag('scheduler.schedule_provider', ['name' => CurrencyKitScheduleProvider::NAME]));
    }

    /**
     * Connection, tables, store, lease, write unit and schema tools.
     *
     * @param array{entity_manager: ?string, table_prefix: string, mapping: string} $doctrine
     */
    private static function storage(ContainerBuilder $container, array $doctrine): void
    {
        $p       = self::P;
        $manager = $doctrine['entity_manager'];
        $container->setAlias($p . 'entity_manager', is_string($manager) && $manager !== '' ? sprintf('doctrine.orm.%s_entity_manager', $manager) : 'doctrine.orm.entity_manager');
        $container->setDefinition($p . 'connection', (new Definition('Doctrine\DBAL\Connection'))->setFactory([new Reference($p . 'entity_manager'), 'getConnection']));
        $container->setDefinition(TableNames::class, (new Definition(TableNames::class))->setArguments([$doctrine['table_prefix']]));
        $container->setDefinition(DbalRateStore::class, (new Definition(DbalRateStore::class))->setArguments([
            new Reference($p . 'connection'),
            new Reference(TableNames::class),
            new Reference(IdGeneratorInterface::class),
            new Reference(CurrencyCatalogueInterface::class),
        ]));
        $container->setAlias(RateStoreInterface::class, DbalRateStore::class);
        $container->setDefinition(DatabaseRunLock::class, (new Definition(DatabaseRunLock::class))->setArguments([new Reference($p . 'connection'), new Reference(TableNames::class), new Reference('clock')]));
        $container->setAlias(RunLockInterface::class, DatabaseRunLock::class);
        $container->setDefinition($p . 'write_unit', (new Definition(WriteUnit::class))->setArguments([
            (new Definition(ConnectionOnlyRegistry::class))->setArguments([new Reference($p . 'connection')]),
            new Definition(WriteUnitPolicy::class),
            null,
            new Reference('clock'),
            new Reference($p . 'logger'),
            null,
            new Reference('nowo_transaction_doctrine.commit_ledger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
        $container->setDefinition(DbalWriteRunner::class, (new Definition(DbalWriteRunner::class))->setArguments([
            new Reference($p . 'write_unit'),
            (new Definition(DoctrineTransactionRunner::class))->setFactory([DoctrineTransactionRunner::class, 'forConnection'])->setArguments([new Reference($p . 'connection'), 1, new Reference($p . 'logger')]),
        ]));
        $container->setAlias(WriteRunnerInterface::class, DbalWriteRunner::class);
        $container->setDefinition(SchemaMetadataListener::class, (new Definition(SchemaMetadataListener::class))
            ->setArguments([$doctrine['table_prefix']])
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));
        $container->setDefinition(SchemaChecker::class, (new Definition(SchemaChecker::class))->setArguments([new Reference($p . 'entity_manager')]));
    }

    /**
     * Fetcher, parsers, sources and the file reader.
     *
     * @param array<string, mixed> $config
     */
    private static function sources(ContainerBuilder $container, array $config): void
    {
        /** @var array{sources: array{ecb: array{enabled: bool, label: string, daily_url: string, last_90_days_url: string, history_url: string}, frankfurter: array{enabled: bool, key: string, label: string, base_uri: ?string, timezone: string, allow_private_network: bool, rebase: bool}, manual: array{label: string}}, outbound: array{timeout: int, max_duration: int, contact_url: string, max_bytes: array{daily: int, last_90_days: int, history: int, api: int}}, backfill: array{max_days: int}} $config */
        $p        = self::P;
        $outbound = $config['outbound'];
        $version  = InstalledVersions::isInstalled('nowo-tech/currency-kit-bundle') ? (InstalledVersions::getPrettyVersion('nowo-tech/currency-kit-bundle') ?? 'dev') : 'dev';
        $container->setDefinition(GuardedHttpFetcher::class, (new Definition(GuardedHttpFetcher::class))->setArguments([
            new Reference($p . 'http_client'),
            new Reference($p . 'url_guard'),
            new Reference('clock'),
            new Reference(CurrencyKitSettings::class),
            sprintf('nowo-currency-kit/%s (+%s)', $version, $outbound['contact_url']),
        ]));
        $container->setAlias(OutboundFetcherInterface::class, GuardedHttpFetcher::class);
        $container->setDefinition(EcbXmlParser::class, new Definition(EcbXmlParser::class));
        $container->setDefinition(CsvRateParser::class, new Definition(CsvRateParser::class));
        $container->setDefinition(FrankfurterJsonParser::class, new Definition(FrankfurterJsonParser::class));
        $container->setDefinition(LocalRateFileReader::class, (new Definition(LocalRateFileReader::class))->setArguments([
            new Reference(EcbXmlParser::class),
            new Reference(CsvRateParser::class),
            $outbound['max_bytes']['history'],
        ]));
        $container->setAlias(RateFileReaderInterface::class, LocalRateFileReader::class);

        $ecb = $config['sources']['ecb'];
        if ($ecb['enabled']) {
            $container->setDefinition(EcbXmlSource::class, (new Definition(EcbXmlSource::class))->setArguments([
                new Reference(OutboundFetcherInterface::class),
                new Reference(EcbXmlParser::class),
                (new Definition(SourceLabel::class))->setArguments([$ecb['label']]),
                $ecb['daily_url'],
                $ecb['last_90_days_url'],
                $ecb['history_url'],
                ['daily' => $outbound['max_bytes']['daily'], 'last_90_days' => $outbound['max_bytes']['last_90_days'], 'history' => $outbound['max_bytes']['history']],
                (float) $outbound['timeout'],
                (float) $outbound['max_duration'],
                $config['backfill']['max_days'],
            ])->addTag(self::SOURCE_TAG));
        }
        $mirror = $config['sources']['frankfurter'];
        if ($mirror['enabled']) {
            $container->setDefinition(FrankfurterCompatibleSource::class, (new Definition(FrankfurterCompatibleSource::class))->setArguments([
                new Reference(OutboundFetcherInterface::class),
                new Reference(FrankfurterJsonParser::class),
                (new Definition(SourceKey::class))->setArguments([$mirror['key']]),
                (new Definition(SourceLabel::class))->setArguments([$mirror['label']]),
                $mirror['base_uri'],
                (new Definition(DateTimeZone::class))->setArguments([$mirror['timezone']]),
                $mirror['allow_private_network'],
                $mirror['rebase'],
                $outbound['max_bytes']['api'],
                (float) $outbound['timeout'],
                (float) $outbound['max_duration'],
                $config['backfill']['max_days'],
            ])->addTag(self::SOURCE_TAG));
        }
        $container->setDefinition(ManualRateSource::class, (new Definition(ManualRateSource::class))
            ->setArguments([(new Definition(SourceLabel::class))->setArguments([$config['sources']['manual']['label']])])
            ->addTag(self::SOURCE_TAG));
    }

    /**
     * The console commands.
     */
    private static function commands(ContainerBuilder $container): void
    {
        $support = new Reference(CommandSupport::class);
        $guard   = new Reference(CurrencyKitGuard::class);
        $set     = new Reference(CurrencyKitSettings::class);
        $sync    = new Reference(RateSynchronizerInterface::class);
        $container->setDefinition(CommandSupport::class, (new Definition(CommandSupport::class))->setArguments([new Reference(CurrencyKitActorResolverInterface::class)]));
        $commands = [
            RatesSyncCommand::class     => [$sync, $guard, $support, $set],
            RatesBackfillCommand::class => [$sync, $guard, $support, $set],
            RatesImportCommand::class   => [$sync, $guard, $support, $set],
            RatesShowCommand::class     => [new Reference(ReferenceRateReaderInterface::class), new Reference(RateMaintenance::class), new Reference(RateResolver::class), $guard, $set],
            RatesChangesCommand::class  => [new Reference(ReferenceRateReaderInterface::class), new Reference(RateMaintenance::class), new Reference(RateResolver::class), $guard, $support],
            ManualSetCommand::class     => [new Reference(ManualRateServiceInterface::class), new Reference(CurrencyCatalogueInterface::class), $guard, $support],
            ManualEndCommand::class     => [new Reference(ManualRateServiceInterface::class), $guard, $support],
            ManualListCommand::class    => [new Reference(ManualRateServiceInterface::class), $guard],
            PruneCommand::class         => [new Reference(RateMaintenance::class), $guard, $support, $set],
            ActorForgetCommand::class   => [new Reference(RateMaintenance::class), $guard, $support],
            ConvertCommand::class       => [new Reference(CurrencyConverterInterface::class), $guard],
            SchemaCheckCommand::class   => [new Reference(SchemaChecker::class)],
        ];
        foreach ($commands as $class => $arguments) {
            $container->setDefinition($class, (new Definition($class))->setArguments($arguments)->addTag('console.command'));
        }
    }
}
