<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Rate;

use DateTimeImmutable;

/**
 * A stored publication row (`<prefix>currency_rate_publication`).
 */
final readonly class PublicationInfo
{
    /**
     * @param string $contentHash sha256 of the sorted `CODE=value` lines
     */
    public function __construct(
        public string $id,
        public SourceKey $source,
        public RateDate $rateDate,
        public RateOrigin $origin,
        public int $currencyCount,
        public string $contentHash,
        public DateTimeImmutable $firstStoredAt,
        public DateTimeImmutable $lastSeenAt,
        public int $version,
    ) {
    }
}
