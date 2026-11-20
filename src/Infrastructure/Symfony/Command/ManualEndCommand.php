<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command;

use Nowo\CurrencyKitBundle\Application\ManualRateServiceInterface;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event\ManualRateAccessCheckEvent;
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

use function ctype_digit;
use function is_string;
use function sprintf;

/**
 * `nowo:currency-kit:rates:manual:end <id> --last-day=Y-m-d --expected-version=N`: ends a manual rate (never deleted).
 */
#[AsCommand(name: 'nowo:currency-kit:rates:manual:end', description: 'End a manual reference rate')]
final class ManualEndCommand extends Command
{
    /**
     * Builds the command.
     */
    public function __construct(
        private readonly ManualRateServiceInterface $manualRates,
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
            ->addArgument('id', InputArgument::REQUIRED, 'Manual rate id')
            ->addOption('last-day', null, InputOption::VALUE_REQUIRED, 'Last day it applies (Y-m-d; the day before it starts: never)')
            ->addOption('expected-version', null, InputOption::VALUE_REQUIRED, 'The version that was read')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who ends the rate (audit)');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $actor = $this->support->actor($input);
            $id    = $input->getArgument('id');
            $id    = is_string($id) ? $id : '';
            $this->guard->assertManualRate(ManualRateAccessCheckEvent::ACTION_END, AccessChannel::Console, $actor, $id);
            $version = CommandSupport::string($input, 'expected-version');
            if ($version === null || !ctype_digit($version)) {
                throw new InvalidValueException('--expected-version (the version that was read) is required.');
            }
            $view = $this->manualRates->end($id, CommandSupport::date($input, 'last-day') ?? throw new InvalidValueException('--last-day is required.'), (int) $version, $actor);
        } catch (Throwable $e) {
            return CommandSupport::fail($io, $e);
        }
        $io->success(sprintf('Manual rate %s ended after %s (version %d).', $view->id, $view->validUntil?->value, $view->version));

        return Command::SUCCESS;
    }
}
