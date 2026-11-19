<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Audit;

/**
 * One audit line (contract section 7.4): an action name (`currency_kit.manual_rate.created`…) and scalar context
 * (actor, currency, old and new rate, window, version; never free text beyond the reason).
 */
final readonly class AuditEntry
{
    public const MANUAL_RATE_CREATED  = 'currency_kit.manual_rate.created';
    public const MANUAL_RATE_UPDATED  = 'currency_kit.manual_rate.updated';
    public const MANUAL_RATE_ENDED    = 'currency_kit.manual_rate.ended';
    public const RATE_CHANGED         = 'currency_kit.rate.changed';
    public const CHANGE_APPLIED       = 'currency_kit.rate.change_applied';
    public const IMPLAUSIBLE_ACCEPTED = 'currency_kit.rate.implausible_accepted';
    public const SYNC_MANUAL_TRIGGER  = 'currency_kit.sync.manual_trigger';
    public const IMPORT               = 'currency_kit.import';
    public const PRUNE                = 'currency_kit.prune';
    public const ACTOR_FORGOTTEN      = 'currency_kit.actor.forgotten';

    /**
     * @param array<string, bool|float|int|string|null> $context
     */
    public function __construct(public string $action, public ?string $actor, public array $context = [])
    {
    }
}
