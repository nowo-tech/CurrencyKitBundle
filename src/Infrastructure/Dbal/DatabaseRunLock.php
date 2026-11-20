<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Dbal;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nowo\CurrencyKitBundle\Domain\Port\RunLockInterface;
use Psr\Clock\ClockInterface;

use function is_string;
use function max;
use function sprintf;

/**
 * The run lease in `<prefix>currency_sync_lock` (`sync.lock.store: database`, contract section 8.2): claimed by a
 * conditional `UPDATE … WHERE locked_until < now` (one statement, no transaction held), the row inserted on first use
 * with insert-or-ignore. A crashed holder's lease expires after the TTL.
 */
final readonly class DatabaseRunLock implements RunLockInterface
{
    /** An expired lease. */
    private const EPOCH = '1970-01-01 00:00:00';

    /**
     * Builds the lock.
     */
    public function __construct(private Connection $connection, private TableNames $tables, private ClockInterface $clock)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function acquire(string $name, string $holder, int $ttlSeconds): int
    {
        DbalSql::insertIgnore($this->connection, $this->tables->syncLock(), ['name' => $name, 'locked_until' => self::EPOCH, 'holder' => ''], ['name']);
        $now     = $this->now();
        $claimed = $this->connection->executeStatement(
            sprintf('UPDATE %s SET locked_until = ?, holder = ? WHERE name = ? AND locked_until < ?', $this->tables->syncLock()),
            [$this->format($now->modify('+' . $ttlSeconds . ' seconds')), $holder, $name, $this->format($now)],
        );
        if ($claimed === 1) {
            return 0;
        }
        $until = $this->connection->fetchOne(sprintf('SELECT locked_until FROM %s WHERE name = ?', $this->tables->syncLock()), [$name]);
        $left  = is_string($until) ? (new DateTimeImmutable($until, new DateTimeZone('UTC')))->getTimestamp() - $now->getTimestamp() : $ttlSeconds;

        return max(1, $left);
    }

    /**
     * {@inheritDoc}
     */
    public function renew(string $name, string $holder, int $ttlSeconds): bool
    {
        // MySQL reports changed rows, not matched ones: the holder is read back instead of trusting the count.
        $this->connection->executeStatement(
            sprintf('UPDATE %s SET locked_until = ? WHERE name = ? AND holder = ?', $this->tables->syncLock()),
            [$this->format($this->now()->modify('+' . $ttlSeconds . ' seconds')), $name, $holder],
        );

        return $this->connection->fetchOne(sprintf('SELECT holder FROM %s WHERE name = ?', $this->tables->syncLock()), [$name]) === $holder;
    }

    /**
     * {@inheritDoc}
     */
    public function release(string $name, string $holder): void
    {
        $this->connection->executeStatement(
            sprintf('UPDATE %s SET locked_until = ? WHERE name = ? AND holder = ?', $this->tables->syncLock()),
            [self::EPOCH, $name, $holder],
        );
    }

    /**
     * Now in UTC, second precision.
     */
    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * A UTC instant string.
     */
    private function format(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }
}
