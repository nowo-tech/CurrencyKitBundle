<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Messenger;

use Nowo\CurrencyKitBundle\Application\CurrencyKitSettings;
use Nowo\CurrencyKitBundle\Application\RateSynchronizerInterface;
use Nowo\CurrencyKitBundle\Domain\Exception\OutboundDisabledException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOptions;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncReport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Handles {@see SyncReferenceRatesMessage}: one sync with `1 + outbound.retries` attempts. Outbound disabled is logged
 * and dropped (nothing to retry); a permanent source error is unrecoverable (no transport retry); transient errors
 * and lock conflicts propagate so the transport may retry (the next scheduled run catches up anyway).
 */
#[AsMessageHandler]
final readonly class SyncReferenceRatesHandler
{
    /**
     * Builds the handler.
     */
    public function __construct(
        private RateSynchronizerInterface $synchronizer,
        private CurrencyKitSettings $settings,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Runs the sync.
     */
    public function __invoke(SyncReferenceRatesMessage $message): ?SyncReport
    {
        try {
            return $this->synchronizer->sync(
                $message->source === null ? null : SourceKey::of($message->source),
                new SyncOptions(attempts: 1 + $this->settings->retries),
            );
        } catch (OutboundDisabledException) {
            $this->logger->notice('Currency kit: scheduled sync skipped, outbound calls are disabled.');

            return null;
        } catch (RateSourceResponseException $e) {
            throw new UnrecoverableMessageHandlingException('The rate source returned a response that cannot be used.', 0, $e);
        }
    }
}
