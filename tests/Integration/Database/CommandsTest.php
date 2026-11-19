<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Nowo\CurrencyKitBundle\Application\ManualRateServiceInterface;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitAuditSinkInterface;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\CommandSupport;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event\CurrencyKitEvents;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event\SyncAccessCheckEvent;
use Nowo\CurrencyKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\CurrencyKitBundle\Tests\Support\EcbXml;
use Nowo\CurrencyKitBundle\Tests\Support\FixtureHttp;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Every console command through the kernel (standard host, SQLite or the CI database).
 */
final class CommandsTest extends KernelTestCase
{
    public function testSyncShowConvertChangesAndHealth(): void
    {
        $this->boot();
        $sync = $this->command('nowo:currency-kit:rates:sync');
        self::assertSame(0, $sync->getStatusCode(), $sync->getDisplay());
        self::assertStringContainsString('stored', $sync->getDisplay());

        $show = $this->command('nowo:currency-kit:rates:show', ['--currency' => 'USD']);
        self::assertStringContainsString('1.0992', $show->getDisplay());
        self::assertStringNotContainsString('GBP', $show->getDisplay());
        self::assertSame(0, $this->command('nowo:currency-kit:rates:show', ['--date' => '2026-11-18'])->getStatusCode());
        self::assertSame(0, $this->command('nowo:currency-kit:rates:show', ['--health' => true])->getStatusCode());
        self::assertSame(0, $this->command('nowo:currency-kit:rates:show', ['--verify' => true])->getStatusCode());

        $convert = $this->command('nowo:currency-kit:convert', ['amount' => '100', 'from' => 'EUR', 'to' => 'USD']);
        self::assertStringContainsString('109.92 USD', $convert->getDisplay());
        self::assertStringContainsString('EUR→USD 1.0992', $convert->getDisplay());
        self::assertStringContainsString('-', $this->command('nowo:currency-kit:convert', ['amount' => '1', 'from' => 'EUR', 'to' => 'EUR', '--date' => '2026-11-18'])->getDisplay());
        self::assertSame(CommandSupport::EXIT_PERMANENT, $this->command('nowo:currency-kit:convert', ['amount' => '1', 'from' => 'usd', 'to' => 'EUR'])->getStatusCode());

        self::assertSame(0, $this->command('nowo:currency-kit:rates:changes', ['--from' => '2026-11-01'])->getStatusCode());
        self::assertSame(CommandSupport::EXIT_PERMANENT, $this->command('nowo:currency-kit:rates:changes', ['--apply' => 'x'])->getStatusCode(), 'writes need --actor');
        self::assertStringContainsString('Nothing to apply', $this->command('nowo:currency-kit:rates:changes', ['--apply' => 'x', '--actor' => 'ops'])->getDisplay());

        $this->connection()->executeStatement("UPDATE nowo_currency_rate SET rate = '9' WHERE currency = 'USD' AND rate_date = '2026-11-19'");
        self::assertSame(1, $this->command('nowo:currency-kit:rates:show', ['--verify' => true])->getStatusCode());
    }

    public function testShowWithoutData(): void
    {
        $this->boot();
        self::assertStringContainsString('No reference rate', $this->command('nowo:currency-kit:rates:show')->getDisplay());
        self::assertSame(1, $this->command('nowo:currency-kit:rates:show', ['--health' => true])->getStatusCode());
        $this->boot([], [], 'standard', false, '2026-12-31 12:00:00');
        $this->command('nowo:currency-kit:rates:import', ['file' => $this->file(FixtureHttp::fixture('ecb/eurofxref-hist-90d.xml')), '--actor' => 'ops']);
        $health = $this->command('nowo:currency-kit:rates:show', ['--health' => true]);
        self::assertSame(1, $health->getStatusCode());
        self::assertStringContainsString('days old', $health->getDisplay());
    }

    public function testSyncExitCodes(): void
    {
        $this->boot(['outbound' => ['enabled' => false]]);
        self::assertSame(CommandSupport::EXIT_OUTBOUND_DISABLED, $this->command('nowo:currency-kit:rates:sync')->getStatusCode());

        $this->boot(['outbound' => ['retries' => 0]]);
        $this->http()->overrides = ['eurofxref' => new MockResponse('', ['http_code' => 503])];
        self::assertSame(CommandSupport::EXIT_TRANSIENT, $this->command('nowo:currency-kit:rates:sync')->getStatusCode());
        $this->http()->overrides = ['eurofxref' => new MockResponse('<not xml')];
        self::assertSame(CommandSupport::EXIT_PERMANENT, $this->command('nowo:currency-kit:rates:sync')->getStatusCode());
        $this->http()->overrides = [];
        // @phpstan-ignore-next-line (PHPStan cannot resolve the narrowed expected type after the overrides reset)
        self::assertSame(CommandSupport::EXIT_PERMANENT, $this->exitOf('nowo:currency-kit:rates:sync', ['--feed' => 'weekly']));
        self::assertSame(0, $this->command('nowo:currency-kit:rates:sync', ['--feed' => '90d', '--actor' => 'ops', '--accept-implausible' => ['USD']])->getStatusCode());
        self::assertSame(0, $this->command('nowo:currency-kit:rates:sync', ['--feed' => 'daily', '--source' => 'ecb'])->getStatusCode());
        $sink = $this->container()->get('nowo_currency_kit.audit_sink');
        self::assertInstanceOf(CurrencyKitAuditSinkInterface::class, $sink);
    }

    public function testDeniedByAListener(): void
    {
        $this->boot();
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CurrencyKitEvents::SYNC_ACCESS_CHECK, static function (SyncAccessCheckEvent $event): void {
            $event->deny();
        });
        $tester = $this->command('nowo:currency-kit:rates:sync');
        self::assertSame(CommandSupport::EXIT_DENIED, $tester->getStatusCode());
        self::assertStringContainsString('ACCESS_002', $tester->getDisplay());
    }

    public function testBackfillImportPrune(): void
    {
        $this->boot();
        self::assertSame(CommandSupport::EXIT_PERMANENT, $this->command('nowo:currency-kit:rates:backfill', ['--actor' => 'ops'])->getStatusCode(), '--from is required');
        $backfill = $this->command('nowo:currency-kit:rates:backfill', ['--from' => '2007-12-01', '--to' => '2008-01-31', '--actor' => 'ops']);
        self::assertSame(0, $backfill->getStatusCode(), $backfill->getDisplay());
        self::assertStringContainsString('2008-01-02', $backfill->getDisplay());

        $import = $this->command('nowo:currency-kit:rates:import', ['file' => $this->file("date,currency,rate\n2026-11-02,USD,1.12\n"), '--format' => 'csv', '--actor' => 'ops']);
        self::assertSame(0, $import->getStatusCode(), $import->getDisplay());
        self::assertSame(CommandSupport::EXIT_PERMANENT, $this->command('nowo:currency-kit:rates:import', ['file' => '/nonexistent', '--actor' => 'ops'])->getStatusCode());

        self::assertSame(CommandSupport::EXIT_PERMANENT, $this->command('nowo:currency-kit:rates:prune', ['--actor' => 'ops'])->getStatusCode());
        $prune = $this->command('nowo:currency-kit:rates:prune', ['--before' => '2008-01-01', '--actor' => 'ops']);
        self::assertStringContainsString('2 publication day(s) deleted', $prune->getDisplay());
    }

    public function testManualRateCommandsAndForget(): void
    {
        $this->boot();
        $set = $this->command('nowo:currency-kit:rates:manual:set', ['--currency' => 'USD', '--rate' => '1.10', '--from' => '2026-11-20', '--reason' => 'Board decision', '--actor' => 'alice']);
        self::assertSame(0, $set->getStatusCode(), $set->getDisplay());
        $manual = $this->container()->get(ManualRateServiceInterface::class);
        self::assertInstanceOf(ManualRateServiceInterface::class, $manual);
        $id = $manual->list()[0]->id;

        self::assertSame(CommandSupport::EXIT_PERMANENT, $this->command('nowo:currency-kit:rates:manual:set', ['--currency' => 'USD', '--rate' => '1.10', '--reason' => 'Board decision', '--actor' => 'alice'])->getStatusCode(), '--from');
        self::assertSame(CommandSupport::EXIT_PERMANENT, $this->command('nowo:currency-kit:rates:manual:set', ['--id' => $id, '--currency' => 'USD', '--rate' => '1.2', '--from' => '2026-11-20', '--reason' => 'Edit rate', '--actor' => 'alice'])->getStatusCode(), '--expected-version');
        $edit = $this->command('nowo:currency-kit:rates:manual:set', ['--id' => $id, '--expected-version' => '1', '--currency' => 'USD', '--rate' => '1.2', '--from' => '2026-11-20', '--reason' => 'Edit rate', '--actor' => 'alice', '--force' => true]);
        self::assertStringContainsString('version 2', $edit->getDisplay());
        self::assertSame(CommandSupport::EXIT_PERMANENT, $this->command('nowo:currency-kit:rates:manual:set', ['--id' => $id, '--expected-version' => '1', '--currency' => 'USD', '--rate' => '1.2', '--from' => '2026-11-20', '--reason' => 'Edit rate', '--actor' => 'alice'])->getStatusCode(), 'stale');

        $list = $this->command('nowo:currency-kit:rates:manual:list', ['--currency' => 'USD', '--status' => 'active']);
        self::assertStringContainsString($id, $list->getDisplay());
        self::assertSame(CommandSupport::EXIT_PERMANENT, $this->command('nowo:currency-kit:rates:manual:list', ['--status' => 'gone'])->getStatusCode());

        self::assertSame(CommandSupport::EXIT_PERMANENT, $this->command('nowo:currency-kit:rates:manual:end', ['id' => $id, '--last-day' => '2026-11-30', '--actor' => 'alice'])->getStatusCode(), '--expected-version');
        self::assertSame(CommandSupport::EXIT_PERMANENT, $this->command('nowo:currency-kit:rates:manual:end', ['id' => $id, '--expected-version' => '2', '--actor' => 'alice'])->getStatusCode(), '--last-day');
        $end = $this->command('nowo:currency-kit:rates:manual:end', ['id' => $id, '--last-day' => '2026-11-30', '--expected-version' => '2', '--actor' => 'alice']);
        self::assertStringContainsString('ended after 2026-11-30', $end->getDisplay());

        self::assertSame(CommandSupport::EXIT_PERMANENT, $this->command('nowo:currency-kit:actor:forget', ['ref' => 'alice'])->getStatusCode(), '--actor');
        self::assertStringContainsString('2 reference(s) removed', $this->command('nowo:currency-kit:actor:forget', ['ref' => 'alice', '--actor' => 'dpo'])->getDisplay());
    }

    public function testKeptChangeIsListedAndApplied(): void
    {
        $this->boot(['sync' => ['on_changed_value' => 'keep']]);
        $this->command('nowo:currency-kit:rates:sync');
        $this->http()->overrides = ['eurofxref-daily.xml' => new MockResponse(EcbXml::of(['2026-11-19' => ['USD' => '1.1']]))];
        $this->command('nowo:currency-kit:rates:sync');
        $list = $this->command('nowo:currency-kit:rates:changes');
        self::assertStringContainsString('1.0992', $list->getDisplay());
        $connection = $this->connection();
        $id         = $connection->fetchOne("SELECT id FROM nowo_currency_rate_change WHERE currency = 'USD'");
        self::assertIsString($id);
        self::assertStringContainsString('Change applied', $this->command('nowo:currency-kit:rates:changes', ['--apply' => $id, '--actor' => 'ops'])->getDisplay());
        self::assertSame(CommandSupport::EXIT_PERMANENT, $this->command('nowo:currency-kit:rates:show', ['--date' => '2026-02-30'])->getStatusCode());
    }

    public function testSchemaCheck(): void
    {
        $this->boot();
        $check = $this->command('nowo:currency-kit:schema-check');
        self::assertSame(0, $check->getStatusCode(), $check->getDisplay());
        self::assertStringContainsString('CREATE TABLE nowo_currency_rate', $this->command('nowo:currency-kit:schema-check', ['--ddl' => true])->getDisplay());
        $this->connection()->executeStatement('DROP TABLE nowo_currency_sync_lock');
        $drift = $this->command('nowo:currency-kit:schema-check');
        self::assertSame(1, $drift->getStatusCode());
        self::assertStringContainsString('nowo_currency_sync_lock', $drift->getDisplay());
    }

    private function connection(): Connection
    {
        $connection = $this->container()->get('nowo_currency_kit.connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function exitOf(string $name, array $input): int
    {
        return $this->command($name, $input)->getStatusCode();
    }

    private function file(string $content): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'cyk');
        file_put_contents($file, $content);

        return $file;
    }
}
