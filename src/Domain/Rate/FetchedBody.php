<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Rate;

/**
 * A decoded, size-capped response body.
 */
final readonly class FetchedBody
{
    /**
     * Wraps the body.
     */
    public function __construct(public string $content)
    {
    }
}
