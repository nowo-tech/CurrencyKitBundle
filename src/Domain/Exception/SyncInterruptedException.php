<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Exception;

use Nowo\CurrencyKitBundle\Domain\Sync\SyncReport;
use Throwable;

/**
 * A sync, backfill or import failed after it had committed some days (`CURRENCY_013`): the stored days stay stored
 * (each day is complete on its own) and the next run continues. `$report` lists what was committed; the failure is the
 * previous exception (a final lock conflict is answered as `CONFLICT_002`: read again, do not repeat blindly).
 */
final class SyncInterruptedException extends CurrencyKitException
{
    /** Host-facing code. */
    public const CODE = 'CURRENCY_013';

    /**
     * Wraps the failure with the partial report.
     */
    public function __construct(public readonly SyncReport $report, Throwable $previous)
    {
        parent::__construct('The run stopped after storing some days; the stored days are kept.', $previous);
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode(): string
    {
        return self::CODE;
    }

    /**
     * {@inheritDoc}
     */
    public function httpStatus(): int
    {
        return 500;
    }
}
