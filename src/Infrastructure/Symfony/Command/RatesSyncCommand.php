<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command;

use Nowo\CurrencyKitBundle\Application\CurrencyKitSettings;
use Nowo\CurrencyKitBundle\Application\RateSynchronizerInterface;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchFeed;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOptions;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncReport;
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

use function count;
use function implode;
use function is_array;
use function is_string;
use function sprintf;

/**
 * `nowo:currency-kit:rates:sync`: fetches the feed that covers the gap and stores new days (contract section 8.2).
 * Exit 0 stored / unchanged / not yet published / skipped (another run holds the lease); 1 transient or final
 * conflict; 2 permanent; 3 outbound disabled; 4 denied.
 */
#[AsCommand(name: 'nowo:currency-kit:rates:sync', description: 'Sync the reference rates from the active source')]
final class RatesSyncCommand extends Command
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
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source key (default: conversion.source)')
            ->addOption('feed', null, InputOption::VALUE_REQUIRED, 'Force a feed: daily or 90d')
            ->addOption('accept-implausible', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Accept an implausible change for this currency code (audited)')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who runs the sync (audit)');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $actor  = $this->support->actor($input, false);
            $source = SourceKey::of(CommandSupport::string($input, 'source') ?? $this->settings->activeSource->value);
            $this->guard->assertSync(SyncAccessCheckEvent::ACTION_SYNC, $source->value, AccessChannel::Console, $actor);
            $feed = match (CommandSupport::string($input, 'feed')) {
                null    => null,
                'daily' => FetchFeed::Daily,
                '90d'   => FetchFeed::Last90Days,
                default => throw new InvalidValueException('--feed must be daily or 90d.'),
            };
            $accept = $input->getOption('accept-implausible');
            $codes  = [];
            foreach (is_array($accept) ? $accept : [] as $code) {
                if (is_string($code)) {
                    $codes[] = $code;
                }
            }
            $report = $this->synchronizer->sync($source, new SyncOptions($feed, $codes, 1 + $this->settings->retries, $actor, $actor instanceof ActorRef));
        } catch (Throwable $e) {
            return CommandSupport::fail($io, $e);
        }

        self::print($io, $report);

        return CommandSupport::ok();
    }

    /**
     * Prints a report.
     */
    public static function print(SymfonyStyle $io, SyncReport $report): void
    {
        $io->definitionList(
            ['source' => $report->source->value],
            ['outcome' => $report->outcome->value . ($report->retryAfter !== null ? sprintf(' (lease held, retry in %d s)', $report->retryAfter) : '')],
            ['feed' => $report->feed->value ?? '-'],
            ['stored' => implode(', ', $report->stored) ?: '-'],
            ['changed' => implode(', ', $report->changed) ?: '-'],
            ['unchanged' => (string) count($report->unchanged)],
            ['warnings' => implode(', ', $report->warnings) ?: '-'],
            ['expected today' => $report->expected ? 'yes' : 'no'],
        );
    }
}
