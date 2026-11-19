<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Domain;

use Nowo\CurrencyKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CurrencyKitBundle\Domain\Exception\CurrencyKitException;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidAmountException;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidCurrencyCodeException;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Exception\ManualRateNotFoundException;
use Nowo\CurrencyKitBundle\Domain\Exception\ManualRateOverlapException;
use Nowo\CurrencyKitBundle\Domain\Exception\ManualRateRefusedException;
use Nowo\CurrencyKitBundle\Domain\Exception\OutboundDisabledException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateNotAvailableException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceUnavailableException;
use Nowo\CurrencyKitBundle\Domain\Exception\StaleManualRateException;
use Nowo\CurrencyKitBundle\Domain\Exception\StaleRateException;
use Nowo\CurrencyKitBundle\Domain\Exception\SyncInterruptedException;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOutcome;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncReport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function preg_match;

final class ExceptionsTest extends TestCase
{
    public function testCodesStatusesAndStaticMessages(): void
    {
        /** @var list<array{CurrencyKitException, string, int}> $cases */
        $cases = [
            [new InvalidCurrencyCodeException(), 'CURRENCY_001', 422],
            [new InvalidAmountException(), 'CURRENCY_002', 422],
            [new RateNotAvailableException(RateNotAvailableException::REASON_BEFORE_HISTORY), 'CURRENCY_003', 404],
            [new StaleRateException('2026-11-01', 18), 'CURRENCY_004', 503],
            [new OutboundDisabledException(), 'CURRENCY_005', 503],
            [new RateSourceUnavailableException(RateSourceUnavailableException::REASON_TRANSPORT), 'CURRENCY_006', 502],
            [new RateSourceResponseException(RateSourceResponseException::REASON_TOO_LARGE, 10), 'CURRENCY_007', 502],
            [new StaleManualRateException(), 'CURRENCY_008', 409],
            [new ManualRateOverlapException(), 'CURRENCY_009', 409],
            [new ManualRateNotFoundException(), 'CURRENCY_010', 404],
            [new ManualRateRefusedException(), 'CURRENCY_011', 422],
            [new InvalidValueException(), 'CURRENCY_012', 422],
            [new SyncInterruptedException(new SyncReport(SourceKey::of('ecb'), SyncOutcome::Stored), new RuntimeException('x')), 'CURRENCY_013', 500],
            [new AccessDeniedException(), 'ACCESS_002', 403],
        ];
        foreach ($cases as [$exception, $code, $status]) {
            self::assertSame($code, $exception->errorCode());
            self::assertSame($status, $exception->httpStatus());
            self::assertSame(1, preg_match('/^[A-Z][A-Z0-9]*_[0-9]{3}$/', $exception->errorCode()));
            self::assertNotSame('', $exception->getMessage());
        }
    }

    public function testReasons(): void
    {
        self::assertSame('future_date', (new RateNotAvailableException(RateNotAvailableException::REASON_FUTURE_DATE))->reason);
        self::assertStringContainsString('lookback', (new RateNotAvailableException(RateNotAvailableException::REASON_NO_PUBLICATION_IN_LOOKBACK))->getMessage());
        self::assertSame((new RateNotAvailableException(RateNotAvailableException::REASON_UNKNOWN_FOR_SOURCE))->getMessage(), (new RateNotAvailableException('other'))->getMessage());
        $stale = new StaleRateException('2026-11-01', 18);
        self::assertSame(['2026-11-01', 18], [$stale->latestDate, $stale->ageDays]);
        self::assertSame(10, (new RateSourceResponseException(RateSourceResponseException::REASON_TOO_LARGE, 10))->bytes);
        self::assertSame('guard_refused', (new RateSourceUnavailableException(RateSourceUnavailableException::REASON_GUARD_REFUSED))->reason);
    }
}
