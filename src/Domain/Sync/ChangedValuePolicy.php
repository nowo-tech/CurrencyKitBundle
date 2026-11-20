<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Sync;

/**
 * What a sync does when a published value changes in a later feed (`sync.on_changed_value`, QC-6).
 */
enum ChangedValuePolicy: string
{
    /** Apply the new value and write the change row, a warning, an audit line and the event (default). */
    case Replace = 'replace';

    /** Keep the stored value; write the change row with `applied = false` and the event (apply later by command). */
    case Keep = 'keep';
}
