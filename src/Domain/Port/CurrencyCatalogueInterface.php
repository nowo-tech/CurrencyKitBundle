<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Port;

use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidCurrencyCodeException;

/**
 * The currency table (contract section 5.1). The default reads the bundled ISO 4217 table; a host may decorate it to
 * add a new ISO code before the bundle updates.
 */
interface CurrencyCatalogueInterface
{
    /**
     * The code, exact (`usd` is refused).
     *
     * @throws InvalidCurrencyCodeException
     */
    public function get(string $code): CurrencyCode;

    /**
     * Whether the code is known and accepted.
     */
    public function exists(string $code): bool;

    /**
     * Every code, sorted.
     *
     * @return list<CurrencyCode>
     */
    public function all(bool $activeOnly = true): array;
}
