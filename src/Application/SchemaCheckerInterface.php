<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

/**
 * Compares the live tables with the bundle's mapping (contract section 6.1). The schema-check command depends on this
 * port; the container wires `Infrastructure\Doctrine\SchemaChecker` (REQ-ARCH-002: the console edge does not depend on
 * Infrastructure).
 */
interface SchemaCheckerInterface
{
    /**
     * The SQL that would align the live tables with the mapping (empty: in sync).
     *
     * @return list<string>
     */
    public function diff(): array;

    /**
     * The reference DDL of the bundle's tables on the connection's platform.
     *
     * @return list<string>
     */
    public function createSql(): array;
}
