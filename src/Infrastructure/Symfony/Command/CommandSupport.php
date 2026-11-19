<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command;

use Nowo\CurrencyKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CurrencyKitBundle\Domain\Exception\CurrencyKitException;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Exception\OutboundDisabledException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceUnavailableException;
use Nowo\CurrencyKitBundle\Domain\Exception\SyncInterruptedException;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitActorResolverInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function count;
use function is_string;
use function sprintf;

/**
 * Shared console helpers: the actor (`--actor`, required by writes without a security user), option parsing and the
 * mapping of failures to messages and exit codes (contract section 8.2 step 7): 1 transient failure or final lock
 * conflict (`CONFLICT_001`, or `CONFLICT_002` when an earlier day was committed), 2 permanent error, 3 outbound
 * disabled, 4 access denied.
 */
final readonly class CommandSupport
{
    /** Exit code of a transient failure or a final lock conflict. */
    public const EXIT_TRANSIENT = 1;

    /** Exit code of a permanent failure (source response, validation, refused input). */
    public const EXIT_PERMANENT = 2;

    /** Exit code when outbound calls are disabled. */
    public const EXIT_OUTBOUND_DISABLED = 3;

    /** Exit code when the guard denied the action. */
    public const EXIT_DENIED = 4;

    /**
     * Builds the helper.
     */
    public function __construct(private CurrencyKitActorResolverInterface $actors)
    {
    }

    /**
     * The actor: `--actor`, else the security user; required for writes.
     *
     * @throws InvalidValueException when a write has no actor
     */
    public function actor(InputInterface $input, bool $required = true): ?ActorRef
    {
        $option = $input->getOption('actor');
        if (is_string($option) && $option !== '') {
            return new ActorRef($option);
        }
        $actor = $this->actors->current();
        if (!$actor instanceof ActorRef && $required) {
            throw new InvalidValueException('This command writes: pass --actor=<who> (audit).');
        }

        return $actor;
    }

    /**
     * A date option, or null.
     *
     * @throws InvalidValueException
     */
    public static function date(InputInterface $input, string $name): ?RateDate
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? RateDate::of($value) : null;
    }

    /**
     * A string option, or null.
     */
    public static function string(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Prints a failure (static messages and codes only) and returns its exit code.
     */
    public static function fail(SymfonyStyle $io, Throwable $e): int
    {
        $committed = false;
        if ($e instanceof SyncInterruptedException) {
            $io->warning(sprintf('Stored before the failure: %d new day(s), %d changed day(s). They stay stored.', count($e->report->stored), count($e->report->changed)));
            $committed = true;
            $e         = $e->getPrevious() ?? $e;
        }
        if ($e instanceof ConcurrencyConflict) {
            $code = $committed || $e->committed ? ConflictCode::COMMITTED : ConflictCode::RETRY_SAFE;
            $io->error(sprintf('[%s] %s', $code, $e->getMessage()));

            return self::EXIT_TRANSIENT;
        }
        if ($e instanceof CurrencyKitException) {
            $io->error(sprintf('[%s] %s', $e->errorCode(), $e->getMessage()));

            return match (true) {
                $e instanceof OutboundDisabledException      => self::EXIT_OUTBOUND_DISABLED,
                $e instanceof RateSourceUnavailableException => self::EXIT_TRANSIENT,
                $e instanceof AccessDeniedException          => self::EXIT_DENIED,
                default                                      => self::EXIT_PERMANENT,
            };
        }

        throw $e;
    }

    /**
     * Success.
     */
    public static function ok(): int
    {
        return Command::SUCCESS;
    }
}
