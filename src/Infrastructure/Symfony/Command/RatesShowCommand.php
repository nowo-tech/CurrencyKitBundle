<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command;

use Nowo\CurrencyKitBundle\Application\CurrencyKitSettings;
use Nowo\CurrencyKitBundle\Application\RateMaintenance;
use Nowo\CurrencyKitBundle\Application\RateResolver;
use Nowo\CurrencyKitBundle\Application\ReferenceRateReaderInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\PublicationInfo;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\AccessChannel;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\CurrencyKitGuard;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\CurrencyKitPermission;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function implode;
use function sprintf;

/**
 * `nowo:currency-kit:rates:show [--date] [--currency] [--health] [--verify]`: the rates of a day (default the latest),
 * a health check that exits 1 when the latest publication is older than `max_staleness_days`, and a verification of
 * the content hashes (exit 1 when rows changed outside the bundle).
 */
#[AsCommand(name: 'nowo:currency-kit:rates:show', description: 'Show stored reference rates, check health or verify hashes')]
final class RatesShowCommand extends Command
{
    /**
     * Builds the command.
     */
    public function __construct(
        private readonly ReferenceRateReaderInterface $reader,
        private readonly RateMaintenance $maintenance,
        private readonly RateResolver $resolver,
        private readonly CurrencyKitGuard $guard,
        private readonly CurrencyKitSettings $settings,
    ) {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source key (default: conversion.source)')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Publication day (Y-m-d, default the latest)')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'Only this currency')
            ->addOption('health', null, InputOption::VALUE_NONE, 'Exit 1 when the latest publication is older than max_staleness_days')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'Recompute the content hashes; exit 1 on a mismatch');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $this->guard->assert(CurrencyKitPermission::ViewRates, AccessChannel::Console);
            $source = SourceKey::of(CommandSupport::string($input, 'source') ?? $this->settings->activeSource->value);
            $latest = $this->reader->latest($source);
            if ($input->getOption('verify') === true) {
                $mismatches = $this->maintenance->verify($source);
                if ($mismatches !== []) {
                    $io->error(sprintf('Rows changed outside the bundle on: %s', implode(', ', $mismatches)));

                    return Command::FAILURE;
                }
                $io->success('Every publication matches its content hash.');

                return Command::SUCCESS;
            }
            if ($input->getOption('health') === true) {
                $age = $latest instanceof PublicationInfo ? $latest->rateDate->daysUntil($this->resolver->today()) : null;
                if ($age === null || $age > $this->settings->maxStalenessDays) {
                    $io->error($age === null ? 'No reference rate is stored.' : sprintf('The latest publication is %d days old.', $age));

                    return Command::FAILURE;
                }
                $io->success(sprintf('Latest publication %s (%d days old).', $latest?->rateDate->value, $age));

                return Command::SUCCESS;
            }
            $date = CommandSupport::date($input, 'date') ?? $latest?->rateDate;
            if (!$date instanceof RateDate) {
                $io->warning('No reference rate is stored. Run nowo:currency-kit:rates:sync.');

                return Command::SUCCESS;
            }
            $only = CommandSupport::string($input, 'currency');
            $rows = [];
            foreach ($this->reader->ratesOn($date, $source) as $code => $rate) {
                if ($only === null || $only === $code) {
                    $rows[] = [$code, $rate];
                }
            }
            $io->title(sprintf('Reference rates %s %s (EUR = 1)', $source->value, $date->value));
            $io->table(['Currency', 'Rate'], $rows);
        } catch (Throwable $e) {
            return CommandSupport::fail($io, $e);
        }

        return Command::SUCCESS;
    }
}
