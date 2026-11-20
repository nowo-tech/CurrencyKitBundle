<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Dbal;

use Nowo\CurrencyKitBundle\Domain\Port\WriteRunnerInterface;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitInterface;

/**
 * A transaction-doctrine write unit (replay after a deadlock, lock-wait timeout or serialization failure; 3 attempts,
 * full jitter; `ConcurrencyConflict` after the last one, `committed: true` when an attempt had committed) around one
 * DBAL transaction on the bundle's connection (`DoctrineTransactionRunner::forConnection()`: savepoint-aware nesting,
 * leftover levels rolled back). Inside a host transaction the unit runs once, as a nested level. No entity manager is
 * touched (the unit's registry holds none).
 */
final readonly class DbalWriteRunner implements WriteRunnerInterface
{
    /**
     * @param WriteUnitInterface $writeUnit the bundle's write unit (registry: {@see ConnectionOnlyRegistry})
     * @param TransactionRunnerInterface $transactions `DoctrineTransactionRunner::forConnection()` on the same connection
     */
    public function __construct(private WriteUnitInterface $writeUnit, private TransactionRunnerInterface $transactions)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function write(callable $work): mixed
    {
        return $this->writeUnit->run(fn (): mixed => $this->transactions->run($work));
    }
}
