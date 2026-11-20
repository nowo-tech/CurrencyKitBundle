<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command;

use Nowo\CurrencyKitBundle\Application\ManualRateServiceInterface;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateInput;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyCatalogueInterface;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event\ManualRateAccessCheckEvent;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\AccessChannel;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\CurrencyKitGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function ctype_digit;
use function sprintf;

/**
 * `nowo:currency-kit:rates:manual:set`: creates a manual rate (EUR → currency), or with `--id` and `--expected-version` edits
 * one on the version that was read. `--reason` and `--actor` are required; `--force` confirms a deviation beyond
 * `manual.max_deviation_percent` (audited as forced).
 */
#[AsCommand(name: 'nowo:currency-kit:rates:manual:set', description: 'Create or edit a manual reference rate (EUR based)')]
final class ManualSetCommand extends Command
{
    /**
     * Builds the command.
     */
    public function __construct(
        private readonly ManualRateServiceInterface $manualRates,
        private readonly CurrencyCatalogueInterface $catalogue,
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
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'ISO 4217 code (1 EUR = rate currency)')
            ->addOption('rate', null, InputOption::VALUE_REQUIRED, 'EUR → currency rate (decimal string)')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First day (Y-m-d)')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'Last day (Y-m-d; omitted: open)')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why (5–500 characters, audited)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm a deviation beyond manual.max_deviation_percent')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Edit this manual rate instead of creating one')
            ->addOption('expected-version', null, InputOption::VALUE_REQUIRED, 'The version that was read (with --id)')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who sets the rate (audit)');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $actor    = $this->support->actor($input);
            $currency = $this->catalogue->get(CommandSupport::string($input, 'currency') ?? '');
            $id       = CommandSupport::string($input, 'id');
            $this->guard->assertManualRate($id === null ? ManualRateAccessCheckEvent::ACTION_CREATE : ManualRateAccessCheckEvent::ACTION_EDIT, AccessChannel::Console, $actor, $id, $currency->code);
            $data = new ManualRateInput(
                $currency,
                CommandSupport::string($input, 'rate') ?? '',
                CommandSupport::date($input, 'from') ?? throw new InvalidValueException('--from is required.'),
                CommandSupport::date($input, 'until'),
                CommandSupport::string($input, 'reason') ?? '',
                $input->getOption('force') === true,
            );
            if ($id === null) {
                $view = $this->manualRates->create($data, $actor);
            } else {
                $version = CommandSupport::string($input, 'expected-version');
                if ($version === null || !ctype_digit($version)) {
                    throw new InvalidValueException('--expected-version (the version that was read) is required with --id.');
                }
                $view = $this->manualRates->update($id, $data, (int) $version, $actor);
            }
        } catch (Throwable $e) {
            return CommandSupport::fail($io, $e);
        }
        $io->success(sprintf('Manual rate %s: 1 EUR = %s %s from %s until %s (version %d).', $view->id, $view->rate, $view->currency->code, $view->validFrom->value, $view->validUntil->value ?? 'open', $view->version));

        return Command::SUCCESS;
    }
}
