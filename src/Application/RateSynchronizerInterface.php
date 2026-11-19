<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Exception\OutboundDisabledException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceUnavailableException;
use Nowo\CurrencyKitBundle\Domain\Exception\SyncInterruptedException;
use Nowo\CurrencyKitBundle\Domain\Port\RateFileReaderInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOptions;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncReport;

/**
 * Sync, backfill and import of reference rates (contract sections 8 and 12.1). Idempotent: a repeated run stores
 * nothing new and reports the days as unchanged. A final lock conflict propagates as transaction-doctrine's
 * `ConcurrencyConflict` (wrapped in {@see SyncInterruptedException} when earlier days were committed).
 */
interface RateSynchronizerInterface
{
    /**
     * Fetches the feed that covers the gap since the latest stored day and stores the new days.
     *
     * @param SourceKey|null $source null: `conversion.source`
     *
     * @throws OutboundDisabledException
     * @throws RateSourceUnavailableException
     * @throws RateSourceResponseException
     * @throws SyncInterruptedException
     */
    public function sync(?SourceKey $source = null, SyncOptions $options = new SyncOptions()): SyncReport;

    /**
     * Loads older days (the full-history feed, or date ranges for an API source); never automatic (QC-10).
     *
     * @throws InvalidValueException when the range is empty or longer than `backfill.max_days`
     * @throws OutboundDisabledException
     * @throws RateSourceUnavailableException
     * @throws RateSourceResponseException
     * @throws SyncInterruptedException
     */
    public function backfill(?SourceKey $source, RateDate $from, ?RateDate $to = null, SyncOptions $options = new SyncOptions()): SyncReport;

    /**
     * Imports a local file (air-gapped installs): stored with origin `import`, audited.
     *
     * @param string $format one of the RateFileReaderInterface `FORMAT_*` constants
     *
     * @throws RateSourceResponseException
     * @throws SyncInterruptedException
     */
    public function import(string $path, ?SourceKey $source = null, string $format = RateFileReaderInterface::FORMAT_ECB_XML, SyncOptions $options = new SyncOptions()): SyncReport;
}
