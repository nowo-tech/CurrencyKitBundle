<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command;

use Nowo\CurrencyKitBundle\Application\SchemaCheckerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `nowo:currency-kit:schema-check [--dump-sql]`: compares the live tables (columns, collations, indexes) with the
 * mapping; exit 1 with the SQL to align them. `--ddl` prints the reference DDL of the platform instead.
 */
#[AsCommand(name: 'nowo:currency-kit:schema-check', description: 'Compare the currency kit tables with the mapping')]
final class SchemaCheckCommand extends Command
{
    /**
     * Builds the command.
     */
    public function __construct(private readonly SchemaCheckerInterface $checker)
    {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->addOption('ddl', null, InputOption::VALUE_NONE, 'Print the reference DDL of this platform');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('ddl') === true) {
            foreach ($this->checker->createSql() as $sql) {
                $output->writeln($sql . ';');
            }

            return Command::SUCCESS;
        }
        $diff = $this->checker->diff();
        if ($diff === []) {
            $io->success('The currency kit tables match the mapping.');

            return Command::SUCCESS;
        }
        $io->error('The currency kit tables differ from the mapping. SQL to align them:');
        foreach ($diff as $sql) {
            $output->writeln($sql . ';');
        }

        return Command::FAILURE;
    }
}
