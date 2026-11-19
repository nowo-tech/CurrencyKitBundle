<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command;

use Nowo\CurrencyKitBundle\Application\RateMaintenance;
use Nowo\CurrencyKitBundle\Application\RateResolver;
use Nowo\CurrencyKitBundle\Application\ReferenceRateReaderInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\RateChange;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event\SyncAccessCheckEvent;
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

/**
 * `nowo:currency-kit:rates:changes [--from] [--to] [--apply=<id>]`: the change log, and applying a change kept by
 * `on_changed_value: keep` (audited; a no-op when already applied).
 */
#[AsCommand(name: 'nowo:currency-kit:rates:changes', description: 'List published-value changes or apply a kept change')]
final class RatesChangesCommand extends Command
{
    /**
     * Builds the command.
     */
    public function __construct(
        private readonly ReferenceRateReaderInterface $reader,
        private readonly RateMaintenance $maintenance,
        private readonly RateResolver $resolver,
        private readonly CurrencyKitGuard $guard,
        private readonly CommandSupport $support,
    ) {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First publication day (default 30 days ago)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last publication day (default today)')
            ->addOption('apply', null, InputOption::VALUE_REQUIRED, 'Apply this kept change id')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who applies (audit)');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $apply = CommandSupport::string($input, 'apply');
            if ($apply !== null) {
                $actor = $this->support->actor($input);
                $this->guard->assertSync(SyncAccessCheckEvent::ACTION_APPLY_CHANGE, 'change', AccessChannel::Console, $actor);
                $change = $this->maintenance->applyChange($apply, $actor);
                if (!$change instanceof RateChange) {
                    $io->note('Nothing to apply: unknown id, already applied, or a removal.');
                } else {
                    $io->success('Change applied.');
                }

                return Command::SUCCESS;
            }
            $this->guard->assert(CurrencyKitPermission::ViewRates, AccessChannel::Console);
            $today = $this->resolver->today();
            $rows  = [];
            foreach ($this->reader->changes(CommandSupport::date($input, 'from') ?? $today->plusDays(-30), CommandSupport::date($input, 'to') ?? $today) as $change) {
                $rows[] = [$change->id, $change->source->value, $change->rateDate->value, $change->currency, $change->oldRate ?? '-', $change->newRate ?? '-', $change->applied ? 'yes' : 'no', $change->detectedAt->format('Y-m-d H:i:s')];
            }
            $io->table(['Id', 'Source', 'Date', 'Currency', 'Old', 'New', 'Applied', 'Detected (UTC)'], $rows);
        } catch (Throwable $e) {
            return CommandSupport::fail($io, $e);
        }

        return Command::SUCCESS;
    }
}
