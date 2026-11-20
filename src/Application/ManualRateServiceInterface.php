<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use Nowo\CurrencyKitBundle\Domain\Exception\ManualRateNotFoundException;
use Nowo\CurrencyKitBundle\Domain\Exception\ManualRateOverlapException;
use Nowo\CurrencyKitBundle\Domain\Exception\ManualRateRefusedException;
use Nowo\CurrencyKitBundle\Domain\Exception\StaleManualRateException;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateInput;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateStatus;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateView;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;

/**
 * Manual rates (overrides) of the instance (contract sections 10 and 12.1). Access is the caller's: the console
 * commands (and the 0.2 UI) go through the currency kit guard first.
 */
interface ManualRateServiceInterface
{
    /**
     * Creates a manual rate.
     *
     * @param ActorRef|null $actor null: the actor resolver's current actor
     *
     * @throws ManualRateOverlapException
     * @throws ManualRateRefusedException
     */
    public function create(ManualRateInput $input, ?ActorRef $actor = null): ManualRateView;

    /**
     * Changes the rate, the window or the reason of an active manual rate on the version that was read.
     *
     * @throws ManualRateNotFoundException
     * @throws StaleManualRateException
     * @throws ManualRateOverlapException
     * @throws ManualRateRefusedException
     */
    public function update(string $id, ManualRateInput $input, int $expectedVersion, ?ActorRef $actor = null): ManualRateView;

    /**
     * Ends a manual rate after `$lastDay` (the day before it starts: it never applies). Never deleted.
     *
     * @throws ManualRateNotFoundException
     * @throws StaleManualRateException
     * @throws ManualRateRefusedException
     */
    public function end(string $id, RateDate $lastDay, int $expectedVersion, ?ActorRef $actor = null): ManualRateView;

    /**
     * One manual rate.
     *
     * @throws ManualRateNotFoundException
     */
    public function get(string $id): ManualRateView;

    /**
     * Manual rates, newest window first.
     *
     * @return list<ManualRateView>
     */
    public function list(?ManualRateStatus $status = null, ?string $currency = null, int $page = 1, int $pageSize = 50): array;
}
