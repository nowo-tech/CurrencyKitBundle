<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Currency\Iso4217Table;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidCurrencyCodeException;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyCatalogueInterface;

/**
 * The default catalogue: the bundled ISO 4217 table (current and historic codes, refused X-codes left out).
 */
final readonly class BundledCurrencyCatalogue implements CurrencyCatalogueInterface
{
    /**
     * {@inheritDoc}
     */
    public function get(string $code): CurrencyCode
    {
        return CurrencyCode::of($code);
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $code): bool
    {
        try {
            CurrencyCode::of($code);

            return true;
        } catch (InvalidCurrencyCodeException) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function all(bool $activeOnly = true): array
    {
        $codes = [];
        foreach (Iso4217Table::CURRENCIES as $code => $row) {
            if ($activeOnly && !$row[2]) {
                continue;
            }
            $codes[] = new CurrencyCode($code, $row[1], $row[2]);
        }

        return $codes;
    }
}
