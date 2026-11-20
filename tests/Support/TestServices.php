<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Support;

use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nowo\CurrencyKitBundle\Application\BatchValidator;
use Nowo\CurrencyKitBundle\Application\BundledCurrencyCatalogue;
use Nowo\CurrencyKitBundle\Application\CurrencyConverter;
use Nowo\CurrencyKitBundle\Application\CurrencyKitSettings;
use Nowo\CurrencyKitBundle\Application\ManualRateService;
use Nowo\CurrencyKitBundle\Application\NullResolvedRateCache;
use Nowo\CurrencyKitBundle\Application\RateMaintenance;
use Nowo\CurrencyKitBundle\Application\RateMemo;
use Nowo\CurrencyKitBundle\Application\RateResolver;
use Nowo\CurrencyKitBundle\Application\RateSourceRegistry;
use Nowo\CurrencyKitBundle\Application\RateSynchronizer;
use Nowo\CurrencyKitBundle\Application\ReferenceRateReader;
use Nowo\CurrencyKitBundle\Application\TargetCalendar;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitActorResolverInterface;
use Nowo\CurrencyKitBundle\Domain\Port\ResolvedRateCacheInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RunLockInterface;
use Nowo\CurrencyKitBundle\Domain\Port\WriteRunnerInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceLabel;
use Nowo\CurrencyKitBundle\Infrastructure\Dbal\ConnectionOnlyRegistry;
use Nowo\CurrencyKitBundle\Infrastructure\Dbal\DatabaseRunLock;
use Nowo\CurrencyKitBundle\Infrastructure\Dbal\DbalRateStore;
use Nowo\CurrencyKitBundle\Infrastructure\Dbal\DbalWriteRunner;
use Nowo\CurrencyKitBundle\Infrastructure\Dbal\TableNames;
use Nowo\CurrencyKitBundle\Infrastructure\Http\GuardedHttpFetcher;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Csv\CsvRateParser;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Ecb\EcbXmlParser;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Ecb\EcbXmlSource;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Frankfurter\FrankfurterCompatibleSource;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Frankfurter\FrankfurterJsonParser;
use Nowo\CurrencyKitBundle\Infrastructure\Source\LocalRateFileReader;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Manual\ManualRateSource;
use Nowo\CurrencyKitBundle\Infrastructure\Support\UuidV7IdGenerator;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The bundle's service graph built by hand on a connection (no kernel): MockClock, MockHttpClient, recording audit
 * sink and dispatcher. Arguments override settings.
 */
final class TestServices
{
    public CurrencyKitSettings $settings;
    public RateMemo $memo;
    public DbalRateStore $store;
    public WriteRunnerInterface $runner;
    public RunLockInterface $lock;
    public RateSourceRegistry $sources;
    public RateResolver $resolver;
    public CurrencyConverter $converter;
    public RateSynchronizer $synchronizer;
    public ManualRateService $manual;
    public RateMaintenance $maintenance;
    public ReferenceRateReader $reader;
    public RecordingAuditSink $audit;
    public RecordingDispatcher $events;
    public BundledCurrencyCatalogue $catalogue;
    public GuardedHttpFetcher $fetcher;
    public CommitLedger $ledger;

    /**
     * @param array<string, mixed> $settings CurrencyKitSettings constructor arguments by name
     */
    public function __construct(
        public Connection $connection,
        public MockClock $clock = new MockClock('2026-11-19 17:00:00', 'Europe/Berlin'),
        public HttpClientInterface $http = new MockHttpClient(),
        array $settings = [],
        bool $mirror = false,
        public ResolvedRateCacheInterface $cache = new NullResolvedRateCache(),
        ?CurrencyKitActorResolverInterface $actors = null,
        ?WriteRunnerInterface $runner = null,
        string $prefix = 'nowo_',
        ?RunLockInterface $lock = null,
    ) {
        $this->settings  = new CurrencyKitSettings(...$settings + ['activeSource' => new SourceKey('ecb')]); // @phpstan-ignore argument.type
        $this->memo      = new RateMemo();
        $this->catalogue = new BundledCurrencyCatalogue();
        $this->audit     = new RecordingAuditSink();
        $this->events    = new RecordingDispatcher();
        $ids             = new UuidV7IdGenerator();
        $tables          = new TableNames($prefix);
        $this->store     = new DbalRateStore($connection, $tables, $ids, $this->catalogue);
        $this->ledger    = new CommitLedger();
        $this->runner    = $runner ?? new DbalWriteRunner(
            new WriteUnit(new ConnectionOnlyRegistry($connection), new WriteUnitPolicy(), null, $this->clock, new NullLogger(), null, $this->ledger),
            DoctrineTransactionRunner::forConnection($connection),
        );
        $this->lock    = $lock ?? new DatabaseRunLock($connection, $tables, $this->clock);
        $this->fetcher = new GuardedHttpFetcher($this->http, new OutboundUrlGuard(false, true, new FakeDns()), $this->clock, $this->settings, 'nowo-currency-kit/test (+https://github.com/nowo-tech/CurrencyKitBundle)');
        $xml           = new EcbXmlParser();
        $limits        = ['daily' => 262144, 'last_90_days' => 4194304, 'history' => 67108864];
        $sources       = [
            new EcbXmlSource($this->fetcher, $xml, new SourceLabel('ECB'), 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml', 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml', 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml', $limits, 10.0, 30.0, 10000),
            new ManualRateSource(new SourceLabel('Manual')),
        ];
        if ($mirror) {
            $sources[] = new FrankfurterCompatibleSource($this->fetcher, new FrankfurterJsonParser(), new SourceKey('mirror'), new SourceLabel('ECB'), 'https://rates.mirror.test/v1/', new DateTimeZone('Europe/Berlin'), false, false, 4194304, 10.0, 30.0, 10000);
        }
        $this->sources      = new RateSourceRegistry($sources);
        $this->resolver     = new RateResolver($this->store, $this->sources, $this->settings, $this->memo, $cache, $this->clock, new NullLogger(), $this->events);
        $this->converter    = new CurrencyConverter($this->resolver, $this->catalogue, $this->settings);
        $this->synchronizer = new RateSynchronizer(
            $this->sources,
            $this->store,
            $this->runner,
            $this->lock,
            new BatchValidator($this->catalogue, $this->store),
            new TargetCalendar(),
            new LocalRateFileReader($xml, new CsvRateParser(), 67108864),
            $ids,
            $this->audit,
            $cache,
            $this->memo,
            $this->settings,
            $this->clock,
            new NullLogger(),
            $this->events,
        );
        $actors ??= new class implements CurrencyKitActorResolverInterface {
            public function current(): ActorRef
            {
                return new ActorRef('admin@example.test');
            }
        };
        $this->manual      = new ManualRateService($this->store, $this->runner, $ids, $actors, $this->audit, $cache, $this->memo, $this->resolver, $this->settings, $this->clock, $this->events);
        $this->maintenance = new RateMaintenance($this->store, $this->runner, $ids, $this->audit, $cache, $this->memo, $this->resolver, $this->settings, $this->clock, $this->events);
        $this->reader      = new ReferenceRateReader($this->store, $this->settings);
    }
}
