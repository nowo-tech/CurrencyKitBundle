<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Dbal;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use LogicException;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateStatus;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateView;
use Nowo\CurrencyKitBundle\Domain\Money\Decimal;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyCatalogueInterface;
use Nowo\CurrencyKitBundle\Domain\Port\IdGeneratorInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RateStoreInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\DayRates;
use Nowo\CurrencyKitBundle\Domain\Rate\PublicationInfo;
use Nowo\CurrencyKitBundle\Domain\Rate\RateChange;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\RateOrigin;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\ChangedValuePolicy;
use Nowo\CurrencyKitBundle\Domain\Sync\DayResult;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOutcome;

use function array_diff_key;
use function array_keys;
use function count;
use function hash;
use function implode;
use function in_array;
use function is_float;
use function is_int;
use function is_string;
use function ksort;
use function sprintf;

/**
 * The default rate store: DBAL on the configured manager's connection (contract sections 6 and 8.3). It never calls
 * `persist`, `flush`, `clear` or `resetManager` on any entity manager. Keys read back are re-checked byte for byte
 * (`===`); instants are written and read as UTC strings; decimals are read as strings (a float from SQLite is printed
 * at scale 12 first).
 */
final readonly class DbalRateStore implements RateStoreInterface
{
    /** Instant format (UTC, second precision). */
    private const INSTANT = 'Y-m-d H:i:s';

    /**
     * Builds the store.
     */
    public function __construct(
        private Connection $connection,
        private TableNames $tables,
        private IdGeneratorInterface $ids,
        private CurrencyCatalogueInterface $catalogue,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function latestPublication(SourceKey $source): ?PublicationInfo
    {
        return $this->publication($this->publications($source)->orderBy('rate_date', 'DESC'));
    }

    /**
     * {@inheritDoc}
     */
    public function earliestPublication(SourceKey $source): ?PublicationInfo
    {
        return $this->publication($this->publications($source)->orderBy('rate_date', 'ASC'));
    }

    /**
     * {@inheritDoc}
     */
    public function publicationOnOrBefore(SourceKey $source, RateDate $date, RateDate $notBefore): ?PublicationInfo
    {
        return $this->publication($this->publications($source)
            ->andWhere('rate_date <= :date')->andWhere('rate_date >= :notBefore')
            ->setParameter('date', $date->value)->setParameter('notBefore', $notBefore->value)
            ->orderBy('rate_date', 'DESC'));
    }

    /**
     * {@inheritDoc}
     */
    public function publicationBefore(SourceKey $source, RateDate $date): ?PublicationInfo
    {
        return $this->publication($this->publications($source)
            ->andWhere('rate_date < :date')->setParameter('date', $date->value)
            ->orderBy('rate_date', 'DESC'));
    }

    /**
     * {@inheritDoc}
     */
    public function rateOnOrBefore(SourceKey $source, string $currency, RateDate $date, RateDate $notBefore): ?array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('source', 'currency', 'rate_date', 'rate')
            ->from($this->tables->rate())
            ->where('source = :source')->andWhere('currency = :currency')
            ->andWhere('rate_date <= :date')->andWhere('rate_date >= :notBefore')
            ->setParameter('source', $source->value)->setParameter('currency', $currency)
            ->setParameter('date', $date->value)->setParameter('notBefore', $notBefore->value)
            ->orderBy('rate_date', 'DESC')
            ->setMaxResults(8)
            ->executeQuery()->fetchAllAssociative();
        foreach ($rows as $row) {
            if ($row['source'] === $source->value && $row['currency'] === $currency) {
                return ['date' => RateDate::of($this->str($row['rate_date'])), 'rate' => $this->decimal($row['rate'])];
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function ratesOn(SourceKey $source, RateDate $date): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('source', 'currency', 'rate')
            ->from($this->tables->rate())
            ->where('source = :source')->andWhere('rate_date = :date')
            ->setParameter('source', $source->value)->setParameter('date', $date->value)
            ->executeQuery()->fetchAllAssociative();
        $rates = [];
        foreach ($rows as $row) {
            if ($row['source'] === $source->value) {
                $rates[$this->str($row['currency'])] = $this->decimal($row['rate']);
            }
        }
        ksort($rates);

        return $rates;
    }

    /**
     * {@inheritDoc}
     */
    public function storeDay(SourceKey $source, DayRates $day, RateOrigin $origin, ChangedValuePolicy $policy, string $runId, DateTimeImmutable $now): DayResult
    {
        $at       = $this->instant($now);
        $newId    = $this->ids->generate();
        $incoming = $day->rates;
        ksort($incoming);
        DbalSql::insertIgnore($this->connection, $this->tables->publication(), [
            'id'              => $newId,
            'source'          => $source->value,
            'rate_date'       => $day->date->value,
            'origin'          => $origin->value,
            'currency_count'  => count($day->rates),
            'content_hash'    => $this->hash($incoming),
            'first_stored_at' => $at,
            'last_seen_at'    => $at,
            'version'         => 1,
        ], ['source', 'rate_date']);

        // One row, fixed order: the publication row first, then the day's rates (contract section 15).
        $locked = $this->forUpdate($this->connection->createQueryBuilder()
            ->select('id', 'source', 'rate_date', 'version')
            ->from($this->tables->publication())
            ->where('source = :source')->andWhere('rate_date = :date')
            ->setParameter('source', $source->value)->setParameter('date', $day->date->value))
            ->executeQuery()->fetchAllAssociative();
        $publication = null;
        foreach ($locked as $row) {
            if ($row['source'] === $source->value) {
                $publication = $row;
            }
        }
        $publicationId = $this->str(($publication ?? throw new LogicException('The publication row vanished inside the write unit.'))['id']);

        if ($publicationId === $newId) {
            foreach ($day->rates as $code => $rate) {
                $this->insertRate($source, $day->date, (string) $code, $rate, $publicationId);
            }

            return new DayResult(SyncOutcome::Stored, count($day->rates));
        }

        $stored  = $this->ratesOn($source, $day->date);
        $changes = [];
        foreach ($day->rates as $code => $rate) {
            $code = (string) $code;
            $old  = $stored[$code] ?? null;
            if ($old === null) {
                $this->insertRate($source, $day->date, $code, $rate, $publicationId);
                $stored[$code] = $rate;
                $changes[]     = $this->insertChange($source, $day->date, $code, null, $rate, $origin, true, $runId, $at);
            } elseif (Decimal::compare($old, $rate) !== 0 && !$this->pendingChange($source, $day->date, $code, $rate)) {
                $applied = $policy === ChangedValuePolicy::Replace;
                if ($applied) {
                    $this->connection->update($this->tables->rate(), ['rate' => $rate], ['source' => $source->value, 'rate_date' => $day->date->value, 'currency' => $code]);
                    $stored[$code] = $rate;
                }
                $changes[] = $this->insertChange($source, $day->date, $code, $old, $rate, $origin, $applied, $runId, $at);
            }
        }
        foreach (array_keys(array_diff_key($stored, $day->rates)) as $code) {
            $code = (string) $code;
            if (!$this->pendingChange($source, $day->date, $code, null)) {
                $changes[] = $this->insertChange($source, $day->date, $code, $stored[$code], null, $origin, false, $runId, $at);
            }
        }

        if ($changes === []) {
            $this->connection->update($this->tables->publication(), ['last_seen_at' => $at], ['id' => $publicationId]);

            return new DayResult(SyncOutcome::Unchanged, count($stored));
        }
        ksort($stored);
        $this->connection->executeStatement(
            sprintf('UPDATE %s SET content_hash = ?, currency_count = ?, version = version + 1, last_seen_at = ? WHERE id = ?', $this->tables->publication()),
            [$this->hash($stored), count($stored), $at, $publicationId],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
        );

        return new DayResult(SyncOutcome::Changed, count($stored), $changes);
    }

    /**
     * {@inheritDoc}
     */
    public function changes(?SourceKey $source, RateDate $from, RateDate $to, int $limit = 500): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('*')->from($this->tables->change())
            ->where('rate_date >= :from')->andWhere('rate_date <= :to')
            ->setParameter('from', $from->value)->setParameter('to', $to->value)
            ->orderBy('detected_at', 'DESC')->addOrderBy('id', 'DESC')
            ->setMaxResults($limit);
        if ($source instanceof SourceKey) {
            // @igor-ignore - a query builder created for this call; nothing is kept on the service
            $query->andWhere('source = :source')->setParameter('source', $source->value);
        }
        $changes = [];
        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            if (!$source instanceof SourceKey || $row['source'] === $source->value) {
                $changes[] = $this->change($row);
            }
        }

        return $changes;
    }

    /**
     * {@inheritDoc}
     */
    public function applyChange(string $changeId, string $runId, DateTimeImmutable $now): ?RateChange
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')->from($this->tables->change())
            ->where('id = :id')->setParameter('id', $changeId)
            ->executeQuery()->fetchAssociative();
        if ($row === false || $row['id'] !== $changeId) {
            return null;
        }
        $kept = $this->change($row);
        if ($kept->applied || $kept->newRate === null) {
            return null;
        }
        $this->forUpdate($this->connection->createQueryBuilder()
            ->select('id')->from($this->tables->publication())
            ->where('source = :source')->andWhere('rate_date = :date')
            ->setParameter('source', $kept->source->value)->setParameter('date', $kept->rateDate->value))
            ->executeQuery()->fetchAllAssociative();
        $current = $this->ratesOn($kept->source, $kept->rateDate)[$kept->currency] ?? null;
        if ($current === null || Decimal::compare($current, $kept->newRate) === 0) {
            return null;
        }
        $at = $this->instant($now);
        $this->connection->update($this->tables->rate(), ['rate' => $kept->newRate], ['source' => $kept->source->value, 'rate_date' => $kept->rateDate->value, 'currency' => $kept->currency]);
        $stored = $this->ratesOn($kept->source, $kept->rateDate);
        $this->connection->executeStatement(
            sprintf('UPDATE %s SET content_hash = ?, version = version + 1, last_seen_at = ? WHERE source = ? AND rate_date = ?', $this->tables->publication()),
            [$this->hash($stored), $at, $kept->source->value, $kept->rateDate->value],
        );

        return $this->insertChange($kept->source, $kept->rateDate, $kept->currency, $current, $kept->newRate, $kept->origin, true, $runId, $at);
    }

    /**
     * {@inheritDoc}
     */
    public function history(SourceKey $source, string $currency, RateDate $from, RateDate $to): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('source', 'currency', 'rate_date', 'rate')
            ->from($this->tables->rate())
            ->where('source = :source')->andWhere('currency = :currency')
            ->andWhere('rate_date >= :from')->andWhere('rate_date <= :to')
            ->setParameter('source', $source->value)->setParameter('currency', $currency)
            ->setParameter('from', $from->value)->setParameter('to', $to->value)
            ->orderBy('rate_date', 'ASC')
            ->executeQuery()->fetchAllAssociative();
        $history = [];
        foreach ($rows as $row) {
            if ($row['source'] === $source->value && $row['currency'] === $currency) {
                $history[] = ['date' => RateDate::of($this->str($row['rate_date'])), 'rate' => $this->decimal($row['rate'])];
            }
        }

        return $history;
    }

    /**
     * {@inheritDoc}
     */
    public function pruneBefore(SourceKey $source, RateDate $before, int $maxDays): int
    {
        $dates = [];
        foreach ($this->publications($source)->andWhere('rate_date < :before')->setParameter('before', $before->value)->orderBy('rate_date', 'ASC')->setMaxResults($maxDays)->executeQuery()->fetchAllAssociative() as $row) {
            if ($row['source'] === $source->value) {
                $dates[] = $this->str($row['rate_date']);
            }
        }
        if ($dates === []) {
            return 0;
        }
        foreach ([$this->tables->rate(), $this->tables->publication()] as $table) {
            $this->connection->executeStatement(
                sprintf('DELETE FROM %s WHERE source = ? AND rate_date IN (?)', $table),
                [$source->value, $dates],
                [ParameterType::STRING, ArrayParameterType::STRING],
            );
        }

        return count($dates);
    }

    /**
     * {@inheritDoc}
     */
    public function verify(SourceKey $source): array
    {
        $rates = [];
        foreach ($this->connection->createQueryBuilder()->select('source', 'rate_date', 'currency', 'rate')->from($this->tables->rate())
            ->where('source = :source')->setParameter('source', $source->value)->executeQuery()->iterateAssociative() as $row) {
            $rates[$this->str($row['rate_date'])][$this->str($row['currency'])] = $this->decimal($row['rate']);
        }
        $mismatches = [];
        foreach ($this->publications($source)->orderBy('rate_date', 'ASC')->executeQuery()->iterateAssociative() as $row) {
            $date = $this->str($row['rate_date']);
            $day  = $rates[$date] ?? [];
            ksort($day);
            if ($this->hash($day) !== $row['content_hash']) {
                $mismatches[] = $date;
            }
        }

        return $mismatches;
    }

    /**
     * {@inheritDoc}
     */
    public function manualRateCovering(string $currency, RateDate $date): ?ManualRateView
    {
        $rows = $this->manualRateQuery()
            ->where('currency = :currency')->andWhere('valid_from <= :date')
            ->andWhere('(valid_until IS NULL OR valid_until >= :date)')
            ->setParameter('currency', $currency)->setParameter('date', $date->value)
            ->orderBy('valid_from', 'DESC')->addOrderBy('id', 'ASC')
            ->executeQuery()->fetchAllAssociative();
        foreach ($rows as $row) {
            if ($row['currency'] === $currency) {
                return $this->manualView($row);
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function manualRate(string $id): ?ManualRateView
    {
        $row = $this->manualRateQuery()->where('id = :id')->setParameter('id', $id)->executeQuery()->fetchAssociative();

        return $row === false || $row['id'] !== $id ? null : $this->manualView($row);
    }

    /**
     * {@inheritDoc}
     */
    public function manualRates(?ManualRateStatus $status, ?string $currency, int $offset, int $limit): array
    {
        $query = $this->manualRateQuery()->orderBy('valid_from', 'DESC')->addOrderBy('id', 'ASC')->setFirstResult($offset)->setMaxResults($limit);
        if ($status instanceof ManualRateStatus) {
            // @igor-ignore - a query builder created for this call; nothing is kept on the service
            $query->andWhere('status = :status')->setParameter('status', $status->value);
        }
        if ($currency !== null) {
            // @igor-ignore - a query builder created for this call; nothing is kept on the service
            $query->andWhere('currency = :currency')->setParameter('currency', $currency);
        }
        $views = [];
        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            if ($currency === null || $row['currency'] === $currency) {
                $views[] = $this->manualView($row);
            }
        }

        return $views;
    }

    /**
     * {@inheritDoc}
     */
    public function lockManualRates(string $currency): array
    {
        $rows = $this->forUpdate($this->manualRateQuery()
            ->where('currency = :currency')->setParameter('currency', $currency)
            ->orderBy('id', 'ASC'))
            ->executeQuery()->fetchAllAssociative();
        $views = [];
        foreach ($rows as $row) {
            if ($row['currency'] === $currency) {
                $views[] = $this->manualView($row);
            }
        }

        return $views;
    }

    /**
     * {@inheritDoc}
     */
    public function insertManualRate(ManualRateView $rate): void
    {
        $this->connection->insert($this->tables->manualRate(), $this->manualRow($rate) + ['id' => $rate->id, 'created_by' => $rate->createdBy?->value, 'created_at' => $this->instant($rate->createdAt)]);
    }

    /**
     * {@inheritDoc}
     */
    public function updateManualRate(ManualRateView $rate, int $expectedVersion): bool
    {
        return $this->connection->update($this->tables->manualRate(), $this->manualRow($rate), ['id' => $rate->id, 'version' => $expectedVersion]) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function forgetActor(ActorRef $actor): int
    {
        $rows = 0;
        foreach (['created_by', 'updated_by'] as $column) {
            $rows += (int) $this->connection->executeStatement(
                sprintf('UPDATE %s SET %s = NULL WHERE %s = ?', $this->tables->manualRate(), $column, $column),
                [$actor->value],
            );
        }

        return $rows;
    }

    /**
     * Inserts one rate row.
     */
    private function insertRate(SourceKey $source, RateDate $date, string $code, string $rate, string $publicationId): void
    {
        $this->connection->insert($this->tables->rate(), [
            'id'             => $this->ids->generate(),
            'source'         => $source->value,
            'rate_date'      => $date->value,
            'currency'       => $code,
            'rate'           => $rate,
            'publication_id' => $publicationId,
        ]);
    }

    /**
     * Inserts one change row (the change table is append-only: no UPDATE or DELETE path exists).
     */
    private function insertChange(SourceKey $source, RateDate $date, string $code, ?string $old, ?string $new, RateOrigin $origin, bool $applied, string $runId, string $at): RateChange
    {
        $id = $this->ids->generate();
        $this->connection->insert($this->tables->change(), [
            'id'          => $id,
            'source'      => $source->value,
            'rate_date'   => $date->value,
            'currency'    => $code,
            'old_rate'    => $old,
            'new_rate'    => $new,
            'origin'      => $origin->value,
            'detected_at' => $at,
            'applied'     => $applied,
            'run_id'      => $runId,
        ], ['applied' => ParameterType::BOOLEAN]);

        return new RateChange($id, $source, $date, $code, $old, $new, $origin, $this->readInstant($at), $applied, $runId);
    }

    /**
     * Whether a not-applied change row with the same new value (or the same removal) is already recorded: a repeated
     * feed never adds the same change twice.
     */
    private function pendingChange(SourceKey $source, RateDate $date, string $code, ?string $new): bool
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('source', 'currency', 'new_rate')->from($this->tables->change())
            ->where('source = :source')->andWhere('rate_date = :date')->andWhere('currency = :currency')->andWhere('applied = :applied')
            ->setParameter('source', $source->value)->setParameter('date', $date->value)->setParameter('currency', $code)
            ->setParameter('applied', false, ParameterType::BOOLEAN)
            ->executeQuery()->fetchAllAssociative();
        foreach ($rows as $row) {
            $recorded = $row['new_rate'] === null ? null : $this->decimal($row['new_rate']);
            $same     = $recorded === $new || ($recorded !== null && $new !== null && Decimal::compare($recorded, $new) === 0);
            if ($same && $row['source'] === $source->value && $row['currency'] === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * `SELECT … FOR UPDATE`; SQLite has no row locks (a writer holds the whole database), so the clause is left out
     * there.
     */
    private function forUpdate(QueryBuilder $query): QueryBuilder
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform ? $query : $query->forUpdate();
    }

    /**
     * A publication query of one source.
     */
    private function publications(SourceKey $source): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('*')->from($this->tables->publication())
            ->where('source = :source')->setParameter('source', $source->value);
    }

    /**
     * The first row of a publication query whose source matches exactly.
     */
    private function publication(QueryBuilder $query): ?PublicationInfo
    {
        $source = $query->getParameter('source');
        foreach ($query->setMaxResults(4)->executeQuery()->fetchAllAssociative() as $row) {
            if ($row['source'] === $source) {
                return new PublicationInfo(
                    $this->str($row['id']),
                    SourceKey::of($this->str($row['source'])),
                    RateDate::of($this->str($row['rate_date'])),
                    RateOrigin::from($this->str($row['origin'])),
                    $this->int($row['currency_count']),
                    $this->str($row['content_hash']),
                    $this->readInstant($row['first_stored_at']),
                    $this->readInstant($row['last_seen_at']),
                    $this->int($row['version']),
                );
            }
        }

        return null;
    }

    /**
     * A manual-rate query.
     */
    private function manualRateQuery(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()->select('*')->from($this->tables->manualRate());
    }

    /**
     * The mutable columns of a manual rate.
     *
     * @return array<string, int|string|null>
     */
    private function manualRow(ManualRateView $rate): array
    {
        return [
            'currency'    => $rate->currency->code,
            'rate'        => $rate->rate,
            'valid_from'  => $rate->validFrom->value,
            'valid_until' => $rate->validUntil?->value,
            'reason'      => $rate->reason,
            'status'      => $rate->status->value,
            'updated_by'  => $rate->updatedBy?->value,
            'updated_at'  => $this->instant($rate->updatedAt),
            'version'     => $rate->version,
        ];
    }

    /**
     * A manual-rate view from a row.
     *
     * @param array<string, mixed> $row
     */
    private function manualView(array $row): ManualRateView
    {
        return new ManualRateView(
            $this->str($row['id']),
            $this->catalogue->get($this->str($row['currency'])),
            $this->decimal($row['rate']),
            RateDate::of($this->str($row['valid_from'])),
            $row['valid_until'] === null ? null : RateDate::of($this->str($row['valid_until'])),
            $this->str($row['reason']),
            ManualRateStatus::from($this->str($row['status'])),
            $row['created_by'] === null ? null : new ActorRef($this->str($row['created_by'])),
            $this->readInstant($row['created_at']),
            $row['updated_by'] === null ? null : new ActorRef($this->str($row['updated_by'])),
            $this->readInstant($row['updated_at']),
            $this->int($row['version']),
        );
    }

    /**
     * A change view from a row.
     *
     * @param array<string, mixed> $row
     */
    private function change(array $row): RateChange
    {
        return new RateChange(
            $this->str($row['id']),
            SourceKey::of($this->str($row['source'])),
            RateDate::of($this->str($row['rate_date'])),
            $this->str($row['currency']),
            $row['old_rate'] === null ? null : $this->decimal($row['old_rate']),
            $row['new_rate'] === null ? null : $this->decimal($row['new_rate']),
            RateOrigin::from($this->str($row['origin'])),
            $this->readInstant($row['detected_at']),
            in_array($row['applied'], [true, 1, '1', 't', 'true'], true),
            $this->str($row['run_id']),
        );
    }

    /**
     * sha256 of the sorted `CODE=value` lines (canonical values).
     *
     * @param array<string, string> $rates sorted by code
     */
    private function hash(array $rates): string
    {
        $lines = [];
        foreach ($rates as $code => $rate) {
            $lines[] = $code . '=' . Decimal::canonical($rate);
        }

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * A decimal column as a canonical string (DECIMAL as string; a float from SQLite printed at scale 12 first).
     */
    private function decimal(mixed $value): string
    {
        // SQLite keeps DECIMAL as REAL: a float is printed as the shortest text that reads back as the same double.
        return Decimal::canonical(is_float($value) ? $this->shortest($value) : $this->str($value));
    }

    /**
     * The shortest fixed-point text of a double that reads back as the same double (at most 16 fraction digits).
     */
    private function shortest(float $value): string
    {
        $text = sprintf('%.16F', $value);
        for ($precision = 1; $precision < 16; ++$precision) {
            $candidate = sprintf('%.' . $precision . 'F', $value);
            if ((float) $candidate === $value) {
                $text = $candidate;

                break;
            }
        }

        return $text;
    }

    /**
     * A string column.
     */
    private function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }

    /**
     * An integer column.
     */
    private function int(mixed $value): int
    {
        return is_int($value) ? $value : (int) $this->str($value);
    }

    /**
     * An instant as a UTC string.
     */
    private function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format(self::INSTANT);
    }

    /**
     * A UTC instant column.
     */
    private function readInstant(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable($this->str($value), new DateTimeZone('UTC'));
    }
}
