<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command;

use Nowo\CurrencyKitBundle\Application\CurrencyConverterInterface;
use Nowo\CurrencyKitBundle\Domain\Conversion\ConversionOptions;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\AccessChannel;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\CurrencyKitGuard;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\CurrencyKitPermission;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function implode;
use function is_string;
use function sprintf;

/**
 * `nowo:currency-kit:convert <amount> <from> <to> [--date=Y-m-d]`: a debug helper printing the full result.
 */
#[AsCommand(name: 'nowo:currency-kit:convert', description: 'Convert an amount at the reference rate (debug helper)')]
final class ConvertCommand extends Command
{
    /**
     * Builds the command.
     */
    public function __construct(private readonly CurrencyConverterInterface $converter, private readonly CurrencyKitGuard $guard)
    {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this
            ->addArgument('amount', InputArgument::REQUIRED, 'Decimal amount')
            ->addArgument('from', InputArgument::REQUIRED, 'ISO 4217 code')
            ->addArgument('to', InputArgument::REQUIRED, 'ISO 4217 code')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Date in the source calendar (Y-m-d, default today)');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $this->guard->assert(CurrencyKitPermission::ViewRates, AccessChannel::Console);
            [$amount, $from, $to] = [$input->getArgument('amount'), $input->getArgument('from'), $input->getArgument('to')];
            $result               = $this->converter->convert(
                is_string($amount) ? $amount : '',
                is_string($from) ? $from : '',
                is_string($to) ? $to : '',
                null,
                new ConversionOptions(onDate: CommandSupport::date($input, 'date')),
            );
        } catch (Throwable $e) {
            return CommandSupport::fail($io, $e);
        }
        $legs = [];
        foreach ($result->legs as $leg) {
            $legs[] = sprintf('EUR→%s %s (%s, %s%s)', $leg->currency->code, $leg->rate, $leg->rateDate->value, $leg->source->value, $leg->manualRateId === null ? '' : ' ' . $leg->manualRateId);
        }
        $io->definitionList(
            ['amount' => $result->amount->amount . ' ' . $result->amount->currency->code],
            ['exact' => $result->exactAmount],
            ['rate' => $result->rate],
            ['rate date' => $result->rateDate->value . ' (requested ' . $result->requestedDate->value . ')'],
            ['source' => $result->source->value . ' (' . $result->sourceLabel->value . ')'],
            ['stale' => $result->stale ? 'yes' : 'no'],
            ['legs' => $legs === [] ? '-' : implode('; ', $legs)],
            ['reference' => 'reference rate, not for payments or accounting'],
        );

        return Command::SUCCESS;
    }
}
