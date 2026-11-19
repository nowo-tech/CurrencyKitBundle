<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Port;

/**
 * Runs one write unit on the bundle's connection (contract section 15): one transaction, replayed after a lock
 * conflict, `ConcurrencyConflict` after the last attempt. Inside a host transaction the unit runs once as a savepoint.
 */
interface WriteRunnerInterface
{
    /**
     * Runs `$work` in a write unit. The work re-reads what it changes on every attempt.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function write(callable $work): mixed;
}
