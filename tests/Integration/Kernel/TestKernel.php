<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Integration\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\ConfigurableCurrencyKitAccessChecker;
use Nowo\CurrencyKitBundle\NowoCurrencyKitBundle;
use Nowo\CurrencyKitBundle\Tests\Support\RecordingAuditSink;
use Nowo\CurrencyKitBundle\Tests\Support\TestDatabase;
use Nowo\OutboundUrlGuardBundle\NowoOutboundUrlGuardBundle;
use Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

use function array_replace_recursive;
use function dirname;
use function md5;
use function serialize;
use function sys_get_temp_dir;

/**
 * Kernel of the integration tests: FrameworkBundle, DoctrineBundle, the URL guard and the bundle; Twig, SecurityBundle
 * and transaction-doctrine's bundle on request. HTTP goes to {@see TestHttpFactory} (a MockHttpClient factory),
 * DNS to the FakeDns seam, and the clock is a MockClock (2026-11-19 17:00 Europe/Berlin).
 *
 * Host kinds (REQ-ARCH-001): `standard` = the default entity manager, attribute mapping; `ddd` = a second connection
 * and entity manager `currency`, XML mapping.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig `nowo_currency_kit` configuration
     * @param array<string, bool> $features twig, security, transaction_doctrine, messenger, scheduler
     * @param 'ddd'|'named'|'standard' $host
     */
    public function __construct(
        private readonly array $bundleConfig = [],
        private readonly array $features = [],
        private readonly string $host = 'standard',
        private readonly string $now = '2026-11-19 17:00:00',
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        if (!$this->feature('no_guard')) {
            yield new NowoOutboundUrlGuardBundle();
        }
        if ($this->feature('twig')) {
            yield new TwigBundle();
        }
        if ($this->feature('security')) {
            yield new SecurityBundle();
        }
        if ($this->feature('transaction_doctrine')) {
            yield new NowoTransactionDoctrineBundle();
        }
        yield new NowoCurrencyKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-currency-kit-kernel/' . $this->id() . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-currency-kit-kernel/' . $this->id() . '/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->register('test.mock_clock', MockClock::class)->setArguments([$this->now, 'Europe/Berlin'])->setPublic(true);
        $container->register('test.http_factory', TestHttpFactory::class)->setPublic(true);
        $container->register('test.checker', ConfigurableCurrencyKitAccessChecker::class)->setArguments([['ROLE_OPS'], [], ['ROLE_OPS']]);
        $container->register('test.audit', RecordingAuditSink::class)->setPublic(true);
        $container->register('logger', NullLogger::class);
        $container->addCompilerPass(new TestOverridesPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1000);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $framework = [
            'test'                  => true,
            'secret'                => 'cyk-test',
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'http_client'           => $this->feature('no_http_client') ? ['enabled' => false] : ['mock_response_factory' => 'test.http_factory'],
            'translator'            => ['fallbacks' => ['en']],
            'cache'                 => ['app' => 'cache.adapter.array'],
        ];
        if ($this->feature('messenger')) {
            $framework['messenger'] = ['transports' => ['sync' => 'sync://'], 'routing' => ['Nowo\CurrencyKitBundle\Infrastructure\Symfony\Messenger\SyncReferenceRatesMessage' => 'sync']];
        }
        if ($this->feature('scheduler')) {
            $framework['scheduler'] = ['enabled' => true];
        }
        $container->extension('framework', $framework);

        $url = TestDatabase::url();
        if ($this->host === 'ddd') {
            $container->extension('doctrine', [
                'dbal' => ['default_connection' => 'default', 'connections' => ['default' => ['url' => $url], 'currency' => ['url' => $url]]],
                'orm'  => ['default_entity_manager' => 'default', 'entity_managers' => [
                    'default'  => ['connection' => 'default'],
                    'currency' => ['connection' => 'currency'],
                ]],
            ]);
        } elseif ($this->host === 'named') {
            // The default entity manager is not called "default" (audit VR-10).
            $container->extension('doctrine', ['dbal' => ['url' => $url], 'orm' => ['default_entity_manager' => 'main', 'entity_managers' => ['main' => ['connection' => 'default']]]]);
        } else {
            $container->extension('doctrine', ['dbal' => ['url' => $url], 'orm' => ['entity_managers' => ['default' => ['connection' => 'default']]]]);
        }
        if ($this->feature('twig')) {
            $container->extension('twig', ['strict_variables' => true]);
        }
        if ($this->feature('security')) {
            $container->extension('security', [
                'providers' => ['memory' => ['memory' => ['users' => [
                    'admin'  => ['password' => 'x', 'roles' => ['ROLE_ADMIN']],
                    'viewer' => ['password' => 'x', 'roles' => ['ROLE_VIEWER']],
                ]]]],
                'role_hierarchy' => ['ROLE_SUPER' => ['ROLE_ADMIN']],
                'firewalls'      => ['main' => ['lazy' => true, 'provider' => 'memory']],
            ]);
        }
        $config = $this->host === 'ddd'
            ? array_replace_recursive(['doctrine' => ['entity_manager' => 'currency', 'mapping' => 'xml']], $this->bundleConfig)
            : array_replace_recursive(['doctrine' => ['mapping' => 'attribute']], $this->bundleConfig);
        $container->extension('nowo_currency_kit', $config);
    }

    private function feature(string $name): bool
    {
        return $this->features[$name] ?? false;
    }

    private function id(): string
    {
        return md5(serialize([$this->bundleConfig, $this->features, $this->host, $this->now]));
    }
}
