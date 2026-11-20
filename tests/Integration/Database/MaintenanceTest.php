<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Integration\Database;

use Nowo\CurrencyKitBundle\Domain\Audit\AuditEntry;
use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateInput;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;

use function sprintf;

final class MaintenanceTest extends DatabaseTestCase
{
    public function testPruneVerifyAndForget(): void
    {
        $this->services->synchronizer->sync();
        $ecb = SourceKey::of('ecb');
        try {
            $this->services->maintenance->prune($ecb, RateDate::of('2026-11-15'), null);
            self::fail('pruned inside the lookback');
        } catch (InvalidValueException) {
        }
        self::assertSame(0, $this->services->maintenance->prune($ecb, RateDate::of('2026-01-01'), null));
        self::assertSame(3, $this->services->maintenance->prune($ecb, RateDate::of('2026-11-11'), new ActorRef('ops')));
        self::assertSame('2026-11-11', $this->services->store->earliestPublication($ecb)?->rateDate->value);
        self::assertContains(AuditEntry::PRUNE, $this->services->audit->actions());

        self::assertSame([], $this->services->maintenance->verify($ecb));
        $this->connection->executeStatement("UPDATE nowo_currency_rate SET rate = '9.5' WHERE currency = 'USD' AND rate_date = '2026-11-12'");
        self::assertSame(['2026-11-12'], $this->services->maintenance->verify($ecb));

        $services = $this->services(['manualAllowPast' => true]);
        $services->manual->create(new ManualRateInput(CurrencyCode::of('USD'), '1.1', RateDate::of('2026-11-20'), null, 'Privacy test'), new ActorRef('alice'));
        self::assertSame(2, $services->maintenance->forgetActor(new ActorRef('alice'), new ActorRef('dpo')));
        self::assertNull($services->manual->list()[0]->createdBy);
        self::assertContains(AuditEntry::ACTOR_FORGOTTEN, $services->audit->actions());
    }

    public function testPruneRunsInBatches(): void
    {
        for ($day = 1; $day <= 3; ++$day) {
            $this->seed(sprintf('2025-01-%02d', $day), ['USD' => '1.1']);
        }
        $this->services->maintenance->prune(SourceKey::of('ecb'), RateDate::of('2025-02-01'), null);
        self::assertNull($this->services->reader->latest());
    }

    public function testLease(): void
    {
        $lock = $this->services->lock;
        self::assertSame(0, $lock->acquire('sync_ecb', 'run-a', 600));
        self::assertSame(600, $lock->acquire('sync_ecb', 'run-b', 600));
        self::assertTrue($lock->renew('sync_ecb', 'run-a', 600));
        self::assertFalse($lock->renew('sync_ecb', 'run-b', 600));
        $lock->release('sync_ecb', 'run-b');
        self::assertGreaterThan(0, $lock->acquire('sync_ecb', 'run-b', 600), 'only the holder releases');
        $lock->release('sync_ecb', 'run-a');
        self::assertSame(0, $lock->acquire('sync_ecb', 'run-b', 600));
        $this->services->clock->modify('+601 seconds');
        self::assertSame(0, $lock->acquire('sync_ecb', 'run-c', 600), 'an expired lease is taken over');
    }
}
