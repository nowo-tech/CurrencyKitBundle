<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Exception\ManualRateOverlapException;
use Nowo\CurrencyKitBundle\Domain\Exception\SyncInterruptedException;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateInput;
use Nowo\CurrencyKitBundle\Domain\Rate\DayRates;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\RateOrigin;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\ChangedValuePolicy;
use Nowo\CurrencyKitBundle\Domain\Sync\DayResult;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOutcome;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\CommandSupport;
use Nowo\CurrencyKitBundle\Tests\Support\TestDatabase;
use Nowo\CurrencyKitBundle\Tests\Support\TestServices;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * REQ-RUNTIME-003 with two connections on a real database (MySQL, MariaDB, PostgreSQL; skipped on SQLite): a held row
 * lock makes the other writer wait, time out, replay and give up with ConcurrencyConflict (CONFLICT_001), nothing is
 * duplicated or lost, a run that committed earlier days answers CONFLICT_002, and the connection stays usable.
 */
final class ConcurrencyTest extends DatabaseTestCase
{
    private Connection $other;

    private TestServices $second;

    protected function setUp(): void
    {
        if (!TestDatabase::isServer()) {
            self::markTestSkipped('Two-connection lock tests need MySQL, MariaDB or PostgreSQL (CI "Databases" job).');
        }
        parent::setUp();
        $this->other = TestDatabase::connection();
        $this->other->executeStatement(TestDatabase::family($this->other) === 'postgresql' ? "SET lock_timeout = '1000ms'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        $this->second = new TestServices($this->other, new MockClock('2026-11-19 17:00:00', 'Europe/Berlin'), $this->http->client);
    }

    protected function tearDown(): void
    {
        if (isset($this->other)) {
            $this->other->close();
        }
        parent::tearDown();
    }

    public function testTwoSyncsOfTheSameDayNeverDuplicate(): void
    {
        $day = new DayRates(RateDate::of('2026-11-19'), ['GBP' => '0.83581', 'USD' => '1.0992']);
        $this->connection->beginTransaction();
        $this->services->store->storeDay(SourceKey::of('ecb'), $day, RateOrigin::Daily, ChangedValuePolicy::Replace, 'run-a', $this->services->clock->now());
        try {
            $this->second->runner->write(fn (): DayResult => $this->second->store->storeDay(SourceKey::of('ecb'), $day, RateOrigin::Daily, ChangedValuePolicy::Replace, 'run-b', $this->second->clock->now()));
            self::fail('the second writer did not wait on the publication row');
        } catch (ConcurrencyConflict $e) {
            self::assertSame(3, $e->attempts);
            self::assertFalse($e->committed);
            self::assertNotNull($e->retryAfterSeconds());
        }
        $this->connection->commit();

        $result = $this->second->runner->write(fn (): DayResult => $this->second->store->storeDay(SourceKey::of('ecb'), $day, RateOrigin::Daily, ChangedValuePolicy::Replace, 'run-b', $this->second->clock->now()));
        self::assertSame(SyncOutcome::Unchanged, $result->outcome);
        self::assertEquals(2, $this->connection->fetchOne('SELECT COUNT(*) FROM nowo_currency_rate'));
        self::assertEquals(1, $this->connection->fetchOne('SELECT COUNT(*) FROM nowo_currency_rate_publication'));
        self::assertEquals(1, $this->other->fetchOne('SELECT 1'), 'the connection is usable afterwards');
    }

    public function testManualOverlapRaceWaitsEvenForTheFirstRateOfACurrency(): void
    {
        // No manual rate exists yet: the guard row serialises the overlap check under READ COMMITTED too (audit VR-1).
        $this->connection->beginTransaction();
        self::assertSame([], $this->services->store->lockManualRates('USD'));
        try {
            $this->second->manual->create(new ManualRateInput(CurrencyCode::of('USD'), '1.1', RateDate::of('2026-12-01'), null, 'Racing window'));
            self::fail('the second writer did not wait');
        } catch (Throwable $e) {
            self::assertInstanceOf(ConcurrencyConflict::class, $e);
        }
        $this->services->manual->create(new ManualRateInput(CurrencyCode::of('USD'), '1.1', RateDate::of('2026-11-25'), null, 'First window'));
        $this->connection->commit();
        try {
            $this->second->manual->create(new ManualRateInput(CurrencyCode::of('USD'), '1.1', RateDate::of('2026-12-01'), null, 'Racing window'));
            self::fail('two overlapping manual rates');
        } catch (ManualRateOverlapException) {
        }
        self::assertCount(1, $this->second->manual->list());
    }

    public function testCommittedThenConflictIsConflict002(): void
    {
        // Day 11-06 is stored partly (the run will change it), day 11-10 fully; the other writer holds 11-10's row.
        $this->seed('2026-11-06', ['USD' => '1.1165']);
        $this->seed('2026-11-10', ['USD' => '1.1214']);
        $this->connection->beginTransaction();
        $this->connection->executeQuery("SELECT id FROM nowo_currency_rate_publication WHERE source = 'ecb' AND rate_date = '2026-11-10' FOR UPDATE")->fetchAllAssociative();
        try {
            $this->second->synchronizer->sync();
            self::fail('no conflict');
        } catch (SyncInterruptedException $e) {
            self::assertSame(['2026-11-06'], $e->report->changed);
            // MySQL stores 11-09 too; MariaDB's next-key lock on 11-10 (repeatable read) makes 11-09 wait as well.
            self::assertContains($e->report->stored, [[], ['2026-11-09']]);
            self::assertInstanceOf(ConcurrencyConflict::class, $e->getPrevious());
            $output = new BufferedOutput();
            self::assertSame(CommandSupport::EXIT_TRANSIENT, CommandSupport::fail(new SymfonyStyle(new ArrayInput([]), $output), $e));
            self::assertStringContainsString('CONFLICT_002', $output->fetch());
        }
        $this->connection->rollBack();
        self::assertSame(SyncOutcome::Changed, $this->second->synchronizer->sync()->outcome, 'the next run continues');
    }

    public function testInsideAHostTransactionTheUnitIsASavepoint(): void
    {
        $this->connection->beginTransaction();
        $this->services->manual->create(new ManualRateInput(CurrencyCode::of('USD'), '1.1', RateDate::of('2026-11-20'), null, 'Host transaction'));
        self::assertSame(1, $this->connection->getTransactionNestingLevel());
        $this->connection->rollBack();
        self::assertSame([], $this->services->manual->list());
    }
}
