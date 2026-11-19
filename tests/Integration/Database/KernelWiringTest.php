<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Integration\Database;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\CurrencyKitBundle\Application\CurrencyConverterInterface;
use Nowo\CurrencyKitBundle\Application\CurrencyKitSettings;
use Nowo\CurrencyKitBundle\Application\RateSynchronizerInterface;
use Nowo\CurrencyKitBundle\Application\ReferenceRateReaderInterface;
use Nowo\CurrencyKitBundle\Bridge\Scheduler\CurrencyKitScheduleProvider;
use Nowo\CurrencyKitBundle\Domain\Conversion\OnStale;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitAccessCheckerInterface;
use Nowo\CurrencyKitBundle\Domain\Port\ResolvedRateCacheInterface;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncReport;
use Nowo\CurrencyKitBundle\Infrastructure\Cache\Psr6ResolvedRateCache;
use Nowo\CurrencyKitBundle\Infrastructure\Doctrine\Entity\ReferenceRate;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Messenger\SyncReferenceRatesMessage;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\AllowAllCurrencyKitAccessChecker;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\ConfigurableCurrencyKitAccessChecker;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Twig\ReferenceMoneyExtension;
use Nowo\CurrencyKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\CurrencyKitBundle\Tests\Support\RecordingAuditSink;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;
use Twig\Environment;

use function memory_get_usage;
use function putenv;

/**
 * The container of both host kinds (REQ-ARCH-001), the optional bridges and the configuration rules.
 */
final class KernelWiringTest extends KernelTestCase
{
    public function testStandardHostWithEverything(): void
    {
        $container = $this->boot(['cache' => ['pool' => 'cache.app'], 'scheduler' => ['enabled' => true]], ['twig' => true, 'security' => true, 'transaction_doctrine' => true, 'messenger' => true, 'scheduler' => true]);
        self::assertInstanceOf(Psr6ResolvedRateCache::class, $container->get('nowo_currency_kit.resolved_rate_cache'));
        self::assertInstanceOf(ConfigurableCurrencyKitAccessChecker::class, $container->get('nowo_currency_kit.access_checker'));
        self::assertTrue($container->has(CurrencyKitScheduleProvider::class));

        $bus = $container->get('messenger.default_bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $handled = $bus->dispatch(new SyncReferenceRatesMessage())->last(HandledStamp::class);
        self::assertInstanceOf(SyncReport::class, $handled?->getResult());

        $twig = $container->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        self::assertTrue($twig->hasExtension(ReferenceMoneyExtension::class));
        $html = $twig->createTemplate("{{ '100'|reference_money('USD', null, 'EUR') }}")->render();
        self::assertSame('≈ $109.92 (reference rate ECB 2026-11-19)', $html);

        // The read-only entities are mapped (attributes on the standard host) for the host's queries.
        $manager = $container->get('nowo_currency_kit.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $rows = $manager->getRepository(ReferenceRate::class)->findBy(['currency' => 'USD']);
        self::assertCount(10, $rows);
    }

    public function testDddHostUsesItsOwnManager(): void
    {
        $container    = $this->boot(['security' => ['access_checker' => 'test.checker']], [], 'ddd');
        $synchronizer = $container->get(RateSynchronizerInterface::class);
        self::assertInstanceOf(RateSynchronizerInterface::class, $synchronizer);
        $synchronizer->sync();
        $reader = $container->get(ReferenceRateReaderInterface::class);
        self::assertInstanceOf(ReferenceRateReaderInterface::class, $reader);
        self::assertSame('2026-11-19', $reader->latest()?->rateDate->value);
        $converter = $container->get(CurrencyConverterInterface::class);
        self::assertInstanceOf(CurrencyConverterInterface::class, $converter);
        self::assertSame('109.92', $converter->convert('100', 'EUR', 'USD')->amount->amount);
        $checker = $container->get('nowo_currency_kit.access_checker');
        self::assertInstanceOf(CurrencyKitAccessCheckerInterface::class, $checker);
    }

    public function testDemoCheckerAndRuntimeValues(): void
    {
        putenv('CYK_ON_STALE=flag'); // @phpstan-ignore-line (test-only environment)
        $container = $this->boot(['security' => ['access_checker' => 'allow_all', 'allow_unauthenticated' => true], 'conversion' => ['on_stale' => '%env(CYK_ON_STALE)%'], 'outbound' => ['enabled' => '%env(bool:CYK_OUTBOUND)%']], [], 'standard', false);
        self::assertInstanceOf(AllowAllCurrencyKitAccessChecker::class, $container->get('nowo_currency_kit.access_checker'));
        putenv('CYK_OUTBOUND=0'); // @phpstan-ignore-line (test-only environment)
        $settings = $container->get(CurrencyKitSettings::class);
        self::assertInstanceOf(CurrencyKitSettings::class, $settings);
        self::assertSame(OnStale::Flag, $settings->onStale);
        self::assertFalse($settings->outboundEnabled);
        self::assertInstanceOf(ResolvedRateCacheInterface::class, $container->get('nowo_currency_kit.resolved_rate_cache'));
        putenv('CYK_ON_STALE'); // @phpstan-ignore-line (test-only environment)
        putenv('CYK_OUTBOUND'); // @phpstan-ignore-line (test-only environment)
    }

    public function testWorkerModeRequestsSeeAManualRateAtOnce(): void
    {
        $container    = $this->boot([], ['twig' => true]);
        $synchronizer = $container->get(RateSynchronizerInterface::class);
        self::assertInstanceOf(RateSynchronizerInterface::class, $synchronizer);
        $synchronizer->sync();
        $converter = $container->get(CurrencyConverterInterface::class);
        self::assertInstanceOf(CurrencyConverterInterface::class, $converter);
        $kernel = $this->kernel;
        self::assertNotNull($kernel);
        $start = memory_get_usage();
        for ($i = 0; $i < 400; ++$i) {
            $request = Request::create('/none');
            $request->setLocale($i % 2 === 0 ? 'en' : 'es');
            try {
                $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST);
            } catch (Throwable) {
            }
            self::assertSame('ecb', $converter->convert('1', 'EUR', 'USD')->source->value);
        }
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $start, 'flat memory');
        $this->command('nowo:currency-kit:rates:manual:set', ['--currency' => 'USD', '--rate' => '1.1', '--from' => '2026-11-19', '--reason' => 'Worker mode', '--actor' => 'ops', '--force' => true]);
        self::assertSame('manual', $converter->convert('1', 'EUR', 'USD')->source->value, 'the commit cleared the memo: the next call sees the manual rate');
    }

    public function testFallbacksWithoutOptionalServices(): void
    {
        $container = $this->boot(['audit' => ['sink' => 'test.audit']], ['no_guard' => true, 'no_http_client' => true, 'logger' => true], 'standard', false);
        self::assertTrue($container->has('nowo_currency_kit.http_client'));
        self::assertTrue($container->has('nowo_currency_kit.url_guard'));
        self::assertTrue($container->has('nowo_currency_kit.logger'));
        self::assertInstanceOf(RecordingAuditSink::class, $container->get('nowo_currency_kit.audit_sink'));
    }

    public function testConfigurationRules(): void
    {
        foreach ([
            'inactive source'        => ['conversion' => ['source' => 'mirror']],
            'mirror without uri'     => ['sources' => ['frankfurter' => ['enabled' => true]]],
            'mirror over http'       => ['sources' => ['frankfurter' => ['enabled' => true, 'base_uri' => 'http://rates.example/v1']]],
            'allow all without flag' => ['security' => ['access_checker' => 'allow_all']],
            'ui in 0.1'              => ['ui' => ['enabled' => true]],
            'bad prefix'             => ['doctrine' => ['table_prefix' => 'Bad-']],
            'bad key'                => ['sources' => ['frankfurter' => ['key' => 'ecb']]],
            'bad time'               => ['sync' => ['times' => ['25:00']]],
            'bad catch-up'           => ['sync' => ['catch_up_time' => '8am']],
            'contact e-mail'         => ['outbound' => ['contact_url' => 'mailto:someone@example.test']],
        ] as $case => $config) {
            try {
                $this->boot($config, [], 'standard', false);
                self::fail('accepted: ' . $case);
            } catch (InvalidConfigurationException) {
            }
        }
        $container = $this->boot(['sources' => ['frankfurter' => ['enabled' => true, 'base_uri' => 'http://mirror.private.test/v1', 'allow_private_network' => true]], 'conversion' => ['source' => 'mirror']], [], 'standard', false);
        self::assertTrue($container->has(CurrencyConverterInterface::class));
        $this->expectException(Throwable::class);
        $this->boot(['conversion' => ['on_stale' => 'later']], [], 'standard', false)->get(CurrencyKitSettings::class);
    }
}
