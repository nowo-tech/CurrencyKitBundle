<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Messenger;

/**
 * Asks for a sync of the reference rates (the Scheduler bridge sends it; a host may dispatch it). The handler runs the
 * same code as `nowo:currency-kit:rates:sync` and is idempotent: it can run twice.
 */
final readonly class SyncReferenceRatesMessage
{
    /**
     * @param string|null $source the source key; null: `conversion.source`
     */
    public function __construct(public ?string $source = null)
    {
    }
}
