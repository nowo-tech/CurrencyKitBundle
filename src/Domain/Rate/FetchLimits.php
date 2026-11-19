<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Rate;

use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;

/**
 * Limits of one outbound GET (contract section 7.2): decoded-byte cap, idle timeout and total duration.
 */
final readonly class FetchLimits
{
    /**
     * @param 'json'|'xml' $format the expected body (sets the Accept header)
     *
     * @throws InvalidValueException
     */
    public function __construct(
        public int $maxBytes,
        public float $timeout,
        public float $maxDuration,
        public string $format = 'xml',
    ) {
        if ($maxBytes < 1 || $timeout <= 0 || $maxDuration <= 0) {
            throw new InvalidValueException('Fetch limits must be positive.');
        }
    }
}
