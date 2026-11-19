<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\EventListener;

use Nowo\CurrencyKitBundle\Application\RateMemo;

/**
 * Clears the request memo at every boundary (contract section 16): `kernel.request` (main and sub requests),
 * `kernel.finish_request`, `kernel.terminate`, `console.terminate` and Messenger's `WorkerMessageHandledEvent`; the
 * memo is also reset by the services resetter (`kernel.reset`).
 */
final readonly class RateMemoResetListener
{
    /**
     * Builds the listener.
     */
    public function __construct(private RateMemo $memo)
    {
    }

    /**
     * Clears the memo (any event).
     */
    public function __invoke(): void
    {
        $this->memo->reset();
    }
}
