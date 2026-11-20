<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command;

use Nowo\CurrencyKitBundle\Application\CurrencyKitSettings;
use Nowo\CurrencyKitBundle\Application\RateMaintenance;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
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

use function sprintf;

/**
 * `nowo:currency-kit:rates:prune --before=Y-m-d`: deletes publication days (and rates) before the date in units of
 * 1 000 days. Refused inside `conversion.max_lookback_days` of today; changes and manual rates are never pruned.
 */
#[AsCommand(name: 'nowo:currency-kit:rates:prune', description: 'Delete stored reference rates before a date')]
final class PruneCommand extends Command
{
    /**
     * Builds the command.
     */
    public function __construct(
        private readonly RateMaintenance $maintenance,
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
            ->addOption('before', null, InputOption::VALUE_REQUIRED, 'Delete days before this date (Y-m-d)')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source key (default: conversion.source)')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who prunes (audit)');
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
            $this->guard->assertSync(SyncAccessCheckEvent::ACTION_PRUNE, $source->value, AccessChannel::Console, $actor);
            $days = $this->maintenance->prune($source, CommandSupport::date($input, 'before') ?? throw new InvalidValueException('--before is required.'), $actor);
        } catch (Throwable $e) {
            return CommandSupport::fail($io, $e);
        }
        $io->success(sprintf('%d publication day(s) deleted.', $days));

        return Command::SUCCESS;
    }
}
