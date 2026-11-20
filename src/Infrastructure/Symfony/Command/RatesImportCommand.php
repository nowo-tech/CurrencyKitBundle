<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command;

use Nowo\CurrencyKitBundle\Application\CurrencyKitSettings;
use Nowo\CurrencyKitBundle\Application\RateSynchronizerInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RateFileReaderInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOptions;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event\SyncAccessCheckEvent;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\AccessChannel;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\CurrencyKitGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function is_string;

/**
 * `nowo:currency-kit:rates:import <file> [--format=ecb-xml|csv]`: stores a local ECB XML or CSV file (air-gapped
 * installs), same parser and limits as the feeds, origin `import`, audited.
 */
#[AsCommand(name: 'nowo:currency-kit:rates:import', description: 'Import reference rates from a local ECB XML or CSV file')]
final class RatesImportCommand extends Command
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
            ->addArgument('file', InputArgument::REQUIRED, 'Path of the file')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source key to store under (default: conversion.source)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'ecb-xml or csv', RateFileReaderInterface::FORMAT_ECB_XML)
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who imports (audit)');
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
            $this->guard->assertSync(SyncAccessCheckEvent::ACTION_IMPORT, $source->value, AccessChannel::Console, $actor);
            $file   = $input->getArgument('file');
            $report = $this->synchronizer->import(is_string($file) ? $file : '', $source, CommandSupport::string($input, 'format') ?? RateFileReaderInterface::FORMAT_ECB_XML, new SyncOptions(null, [], 1, $actor, true));
        } catch (Throwable $e) {
            return CommandSupport::fail($io, $e);
        }
        RatesSyncCommand::print($io, $report);

        return CommandSupport::ok();
    }
}
