<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Integration\Database;

use Nowo\CurrencyKitBundle\Domain\Audit\AuditEntry;
use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Event\ManualRateCreated;
use Nowo\CurrencyKitBundle\Domain\Event\ManualRateEnded;
use Nowo\CurrencyKitBundle\Domain\Event\ManualRateUpdated;
use Nowo\CurrencyKitBundle\Domain\Exception\ManualRateNotFoundException;
use Nowo\CurrencyKitBundle\Domain\Exception\ManualRateOverlapException;
use Nowo\CurrencyKitBundle\Domain\Exception\ManualRateRefusedException;
use Nowo\CurrencyKitBundle\Domain\Exception\StaleManualRateException;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateInput;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateStatus;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateView;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;

final class ManualRateServiceTest extends DatabaseTestCase
{
    private static function input(string $rate = '1.1', string $from = '2026-11-20', ?string $until = null, bool $force = false, string $currency = 'USD'): ManualRateInput
    {
        return new ManualRateInput(CurrencyCode::of($currency), $rate, RateDate::of($from), $until === null ? null : RateDate::of($until), 'Negotiated board rate', $force);
    }

    public function testCreateUpdateEndList(): void
    {
        $manual  = $this->services->manual;
        $created = $manual->create(self::input());
        self::assertSame(1, $created->version);
        self::assertSame('admin@example.test', $created->createdBy?->value, 'the actor resolver');
        self::assertSame(ManualRateStatus::Active, $created->status);
        self::assertSame($created->id, $manual->get($created->id)->id);

        $updated = $manual->update($created->id, self::input('1.12', '2026-11-20', '2026-12-31'), 1, new ActorRef('ops'));
        self::assertSame(2, $updated->version);
        self::assertSame('1.12', $updated->rate);
        self::assertSame('ops', $updated->updatedBy?->value);
        self::assertSame('admin@example.test', $updated->createdBy?->value);

        $same = $manual->update($created->id, self::input('1.13', '2026-11-20', '2026-12-31'), 2);
        self::assertSame(3, $same->version, 'same window: no overlap lock needed');

        $ended = $manual->end($created->id, RateDate::of('2026-11-30'), 3);
        self::assertSame(ManualRateStatus::Ended, $ended->status);
        self::assertSame('2026-11-30', $ended->validUntil?->value);

        self::assertCount(1, $manual->list());
        self::assertCount(1, $manual->list(ManualRateStatus::Ended, 'USD'));
        self::assertCount(0, $manual->list(ManualRateStatus::Active));
        self::assertCount(0, $manual->list(null, 'GBP'));
        self::assertCount(0, $manual->list(null, null, 2, 1000));
        self::assertSame([AuditEntry::MANUAL_RATE_CREATED, AuditEntry::MANUAL_RATE_UPDATED, AuditEntry::MANUAL_RATE_UPDATED, AuditEntry::MANUAL_RATE_ENDED], $this->services->audit->actions());
        self::assertCount(1, $this->services->events->of(ManualRateCreated::class));
        self::assertCount(2, $this->services->events->of(ManualRateUpdated::class));
        self::assertCount(1, $this->services->events->of(ManualRateEnded::class));

        // The ended rate keeps covering its closed window; the next window may start after it.
        $next = $manual->create(self::input('1.2', '2026-12-01'));
        self::assertSame('1.2', $next->rate);
    }

    public function testOverlapStaleAndRefusals(): void
    {
        $manual  = $this->services->manual;
        $created = $manual->create(self::input('1.1', '2026-11-20', '2026-11-30'));
        $other   = $manual->create(self::input('1.1', '2026-12-05'));
        foreach ([
            'overlap on create' => static fn (): ManualRateView => $manual->create(self::input('1.1', '2026-11-25')),
            'overlap on update' => static fn (): ManualRateView => $manual->update($created->id, self::input('1.1', '2026-11-20', '2026-12-10'), 1),
        ] as $case => $call) {
            try {
                $call();
                self::fail($case);
            } catch (ManualRateOverlapException) {
            }
        }
        foreach ([
            'stale update' => static fn (): ManualRateView => $manual->update($created->id, self::input(), 7),
            'stale end'    => static fn (): ManualRateView => $manual->end($created->id, RateDate::of('2026-11-25'), 7),
        ] as $case => $call) {
            try {
                $call();
                self::fail($case);
            } catch (StaleManualRateException) {
            }
        }
        foreach ([
            'unknown update' => static fn (): ManualRateView => $manual->update('missing', self::input(), 1),
            'unknown end'    => static fn (): ManualRateView => $manual->end('missing', RateDate::of('2026-11-25'), 1),
            'unknown get'    => static fn (): ManualRateView => $manual->get('missing'),
        ] as $case => $call) {
            try {
                $call();
                self::fail($case);
            } catch (ManualRateNotFoundException) {
            }
        }
        foreach ([
            'past window'      => static fn (): ManualRateView => $manual->create(self::input('1.1', '2026-11-01', '2026-11-02')),
            'currency change'  => static fn (): ManualRateView => $manual->update($created->id, self::input('1.1', '2026-11-20', '2026-11-30', false, 'GBP'), 1),
            'end outside'      => static fn (): ManualRateView => $manual->end($created->id, RateDate::of('2026-12-01'), 1),
            'end before start' => static fn (): ManualRateView => $manual->end($created->id, RateDate::of('2026-11-18'), 1),
        ] as $case => $call) {
            try {
                $call();
                self::fail($case);
            } catch (ManualRateRefusedException) {
            }
        }
        $retracted = $manual->end($other->id, RateDate::of('2026-12-04'), 1);
        self::assertSame('2026-12-04', $retracted->validUntil?->value, 'ended the day before it starts: never applies');
        foreach ([
            'end twice'    => static fn (): ManualRateView => $manual->end($other->id, RateDate::of('2026-12-04'), 2),
            'update ended' => static fn (): ManualRateView => $manual->update($other->id, self::input('1.1', '2026-12-05'), 2),
        ] as $case => $call) {
            try {
                $call();
                self::fail($case);
            } catch (ManualRateRefusedException) {
            }
        }
    }

    public function testDeviationGateAndPastWindows(): void
    {
        $this->services->synchronizer->sync();
        $manual = $this->services->manual;
        try {
            $manual->create(self::input('2.5'));
            self::fail('deviation accepted');
        } catch (ManualRateRefusedException) {
        }
        $forced = $manual->create(self::input('2.5', '2026-11-20', null, true));
        self::assertTrue($this->services->audit->entries[0]->context['forced']);
        self::assertSame('2.5', $forced->rate);

        $past = $this->services(['manualAllowPast' => true])->manual->create(self::input('1.1', '2026-01-01', '2026-01-31'));
        self::assertSame('2026-01-01', $past->validFrom->value);
        $manualSource = $this->services(['activeSource' => new SourceKey('manual')])->manual->create(self::input('9', '2027-01-01', null, false, 'GBP'));
        self::assertSame('9', $manualSource->rate, 'no deviation gate without a source');
    }

    public function testRacingUpdateLosesOnTheVersion(): void
    {
        $manual  = $this->services->manual;
        $created = $manual->create(self::input());
        $this->connection->executeStatement('UPDATE nowo_currency_manual_rate SET version = 5 WHERE id = ?', [$created->id]);
        $store = $this->services->store;
        $view  = $store->manualRate($created->id);
        self::assertNotNull($view);
        self::assertFalse($store->updateManualRate($view, 1), 'a write on an old version changes nothing');
    }
}
