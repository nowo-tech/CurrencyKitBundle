<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Port;

/**
 * The sync run lease (contract section 8.2): one sync per source across workers and hosts. A lease, not a row lock:
 * no transaction stays open while the run fetches.
 */
interface RunLockInterface
{
    /**
     * Claims the lease.
     *
     * @return int 0 when claimed, else the seconds left on the lease another holder has (at least 1)
     */
    public function acquire(string $name, string $holder, int $ttlSeconds): int;

    /**
     * Extends a lease this holder has; false when it was lost.
     */
    public function renew(string $name, string $holder, int $ttlSeconds): bool;

    /**
     * Gives the lease back (no-op when another holder has it).
     */
    public function release(string $name, string $holder): void;
}
