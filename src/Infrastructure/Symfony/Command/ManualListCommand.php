<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command;

use Nowo\CurrencyKitBundle\Application\ManualRateServiceInterface;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateStatus;
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

/**
 * `nowo:currency-kit:rates:manual:list [--status] [--currency] [--page]`.
 */
#[AsCommand(name: 'nowo:currency-kit:rates:manual:list', description: 'List manual reference rates')]
final class ManualListCommand extends Command
{
    /**
     * Builds the command.
     */
    public function __construct(private readonly ManualRateServiceInterface $manualRates, private readonly CurrencyKitGuard $guard)
    {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'active or ended')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'Only this currency')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page (50 per page)', '1');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $this->guard->assertManualRate(ManualRateAccessCheckEvent::ACTION_VIEW, AccessChannel::Console, null);
            $status = CommandSupport::string($input, 'status');
            $rows   = [];
            foreach ($this->manualRates->list($status === null ? null : (ManualRateStatus::tryFrom($status) ?? throw new InvalidValueException('--status must be active or ended.')), CommandSupport::string($input, 'currency'), (int) CommandSupport::string($input, 'page')) as $view) {
                $rows[] = [$view->id, $view->currency->code, $view->rate, $view->validFrom->value, $view->validUntil->value ?? 'open', $view->status->value, (string) $view->version, $view->reason];
            }
            $io->table(['Id', 'Currency', 'Rate', 'From', 'Until', 'Status', 'Version', 'Reason'], $rows);
        } catch (Throwable $e) {
            return CommandSupport::fail($io, $e);
        }

        return Command::SUCCESS;
    }
}
