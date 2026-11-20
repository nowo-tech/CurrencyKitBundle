<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Sync;

/**
 * Outcome of a sync run or of one stored day (contract section 5.4).
 */
enum SyncOutcome: string
{
    case Stored          = 'stored';
    case Unchanged       = 'unchanged';
    case Changed         = 'changed';
    case NotYetPublished = 'not_yet_published';
    case Disabled        = 'disabled';
    case Skipped         = 'skipped';
    case Failed          = 'failed';
}
