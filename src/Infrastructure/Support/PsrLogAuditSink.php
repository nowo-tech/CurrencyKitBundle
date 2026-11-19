<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Support;

use Nowo\CurrencyKitBundle\Domain\Audit\AuditEntry;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitAuditSinkInterface;
use Psr\Log\LoggerInterface;

/**
 * The default audit sink: one structured `info` line per entry (constant message, context only).
 */
final readonly class PsrLogAuditSink implements CurrencyKitAuditSinkInterface
{
    /**
     * Builds the sink.
     */
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function record(AuditEntry $entry): void
    {
        $this->logger->info('Currency kit audit.', ['action' => $entry->action, 'actor' => $entry->actor] + $entry->context);
    }
}
