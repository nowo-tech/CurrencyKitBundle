<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Support;

use Nowo\CurrencyKitBundle\Domain\Audit\AuditEntry;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitAuditSinkInterface;

/**
 * Keeps audit entries for assertions.
 */
final class RecordingAuditSink implements CurrencyKitAuditSinkInterface
{
    /** @var list<AuditEntry> */
    public array $entries = [];

    public function record(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return array_map(static fn (AuditEntry $e): string => $e->action, $this->entries);
    }
}
