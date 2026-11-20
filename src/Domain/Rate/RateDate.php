<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Rate;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Stringable;

use function intdiv;
use function preg_match;

/**
 * A calendar date (`Y-m-d`) of a source's publication calendar (Europe/Berlin for the ECB); no time (contract 5.2).
 */
final readonly class RateDate implements Stringable
{
    /** The `Y-m-d` string. */
    public string $value;

    /**
     * @throws InvalidValueException when the string is not a real `Y-m-d` date between 1900 and 2999
     */
    public function __construct(string $value)
    {
        $date = preg_match('/^(19|2\d)\d{2}-\d{2}-\d{2}$/D', $value) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidValueException('The date is not a valid Y-m-d date.');
        }
        $this->value = $value;
    }

    /**
     * `RateDate::of('2026-11-19')`.
     *
     * @throws InvalidValueException
     */
    public static function of(string $value): self
    {
        return new self($value);
    }

    /**
     * The calendar date of an instant in a time zone (`2026-11-19 23:30 America/New_York` is 2026-11-20 in Berlin).
     */
    public static function fromInstant(DateTimeInterface $instant, DateTimeZone $zone): self
    {
        return new self(DateTimeImmutable::createFromInterface($instant)->setTimezone($zone)->format('Y-m-d'));
    }

    /**
     * Midnight UTC of the date (for DBAL `date` parameters and arithmetic).
     */
    public function toDateTime(): DateTimeImmutable
    {
        /** @var DateTimeImmutable $date always valid: checked in the constructor */
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $this->value, new DateTimeZone('UTC'));

        return $date;
    }

    /**
     * The date `$days` calendar days later (negative: earlier).
     */
    public function plusDays(int $days): self
    {
        return new self($this->toDateTime()->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d'));
    }

    /**
     * Calendar days from this date to `$other` (positive when `$other` is later).
     */
    public function daysUntil(self $other): int
    {
        return intdiv($other->toDateTime()->getTimestamp() - $this->toDateTime()->getTimestamp(), 86400);
    }

    /**
     * ISO day of the week: 1 (Monday) … 7 (Sunday).
     */
    public function dayOfWeek(): int
    {
        return (int) $this->toDateTime()->format('N');
    }

    /**
     * -1, 0 or 1 (string order is date order for `Y-m-d`).
     */
    public function compare(self $other): int
    {
        return $this->value <=> $other->value;
    }

    /**
     * Whether this date is before `$other`.
     */
    public function isBefore(self $other): bool
    {
        return $this->value < $other->value;
    }

    /**
     * Whether this date is after `$other`.
     */
    public function isAfter(self $other): bool
    {
        return $this->value > $other->value;
    }

    /**
     * Same date.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The `Y-m-d` string.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
