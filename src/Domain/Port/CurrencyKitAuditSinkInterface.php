<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Port;

use Nowo\CurrencyKitBundle\Domain\Audit\AuditEntry;

/**
 * Where audit lines go (contract section 7.4): one structured PSR log line by default.
 */
interface CurrencyKitAuditSinkInterface
{
    /**
     * Records one entry (called after the commit).
     */
    public function record(AuditEntry $entry): void;
}
