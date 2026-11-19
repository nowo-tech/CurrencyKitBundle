<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command;

use Nowo\CurrencyKitBundle\Application\CurrencyKitSettings;
use Nowo\CurrencyKitBundle\Application\RateSynchronizerInterface;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOptions;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event\SyncAccessCheckEvent;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\AccessChannel;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\CurrencyKitGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * `nowo:currency-kit:rates:backfill --from=Y-m-d [--to=Y-m-d]`: loads older days (the full-history feed for the ECB,
 * date ranges for an API source); resumable (stored days come back unchanged). Never automatic (QC-10).
 */
#[AsCommand(name: 'nowo:currency-kit:rates:backfill', description: 'Load older reference rates (full history or date ranges)')]
final class RatesBackfillCommand extends Command
{
    /**
     * Builds the command.
     */
    public function __construct(
        private readonly RateSynchronizerInterface $synchronizer,
        private readonly CurrencyKitGuard $guard,
        private readonly CommandSupport $support,
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
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First day (Y-m-d)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last day (Y-m-d, default today)')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source key (default: conversion.source)')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who runs the backfill (audit)');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $actor  = $this->support->actor($input);
            $source = SourceKey::of(CommandSupport::string($input, 'source') ?? $this->settings->activeSource->value);
            $from   = CommandSupport::date($input, 'from') ?? throw new InvalidValueException('--from is required.');
            $this->guard->assertSync(SyncAccessCheckEvent::ACTION_BACKFILL, $source->value, AccessChannel::Console, $actor);
            $report = $this->synchronizer->backfill($source, $from, CommandSupport::date($input, 'to'), new SyncOptions(null, [], 1 + $this->settings->retries, $actor, true));
        } catch (Throwable $e) {
            return CommandSupport::fail($io, $e);
        }
        RatesSyncCommand::print($io, $report);

        return CommandSupport::ok();
    }
}
