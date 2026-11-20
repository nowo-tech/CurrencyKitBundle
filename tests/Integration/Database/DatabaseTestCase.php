<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Nowo\CurrencyKitBundle\Domain\Rate\DayRates;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\RateOrigin;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\ChangedValuePolicy;
use Nowo\CurrencyKitBundle\Domain\Sync\DayResult;
use Nowo\CurrencyKitBundle\Tests\Support\FixtureHttp;
use Nowo\CurrencyKitBundle\Tests\Support\TestDatabase;
use Nowo\CurrencyKitBundle\Tests\Support\TestServices;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A fresh database per test (SQLite, or the CI matrix through `NOWO_TEST_DATABASE_URL`) and the hand-built services.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;

    protected EntityManager $entityManager;

    protected FixtureHttp $http;

    protected TestServices $services;

    protected function setUp(): void
    {
        [$this->connection, $this->entityManager] = TestDatabase::fresh();
        $this->http                               = FixtureHttp::ecb();
        $this->services                           = $this->services();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function services(array $settings = [], string $now = '2026-11-19 17:00:00', bool $mirror = false): TestServices
    {
        return new TestServices($this->connection, new MockClock($now, 'Europe/Berlin'), $this->http->client, $settings, $mirror);
    }

    /**
     * Stores a day directly (no feed), outside the synchronizer.
     *
     * @param array<string, string> $rates
     */
    protected function seed(string $date, array $rates, string $source = 'ecb'): void
    {
        $this->services->runner->write(fn (): DayResult => $this->services->store->storeDay(SourceKey::of($source), new DayRates(RateDate::of($date), $rates), RateOrigin::Daily, ChangedValuePolicy::Replace, 'seed', $this->services->clock->now()));
    }
}
