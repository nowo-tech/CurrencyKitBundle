<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command;

use Nowo\CurrencyKitBundle\Application\RateMaintenance;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
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

use function is_string;
use function sprintf;

/**
 * `nowo:currency-kit:actor:forget <ref>`: sets an actor reference to NULL on manual rates (privacy, contract
 * section 14). The audit sink keeps its own retention.
 */
#[AsCommand(name: 'nowo:currency-kit:actor:forget', description: 'Remove an actor reference from manual rates')]
final class ActorForgetCommand extends Command
{
    /**
     * Builds the command.
     */
    public function __construct(
        private readonly RateMaintenance $maintenance,
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
            ->addArgument('ref', InputArgument::REQUIRED, 'The actor reference to forget')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who asks (audit)');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $actor = $this->support->actor($input);
            $this->guard->assert(CurrencyKitPermission::ManageManualRates, AccessChannel::Console, $actor);
            $ref  = $input->getArgument('ref');
            $rows = $this->maintenance->forgetActor(new ActorRef(is_string($ref) ? $ref : ''), $actor);
        } catch (Throwable $e) {
            return CommandSupport::fail($io, $e);
        }
        $io->success(sprintf('%d reference(s) removed.', $rows));

        return Command::SUCCESS;
    }
}
