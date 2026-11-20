<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Infrastructure;

use LogicException;
use Nowo\CurrencyKitBundle\Application\BatchValidator;
use Nowo\CurrencyKitBundle\Application\BundledCurrencyCatalogue;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Exception\SyncInterruptedException;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitActorResolverInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RateStoreInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\DayRates;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOutcome;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncReport;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Command\CommandSupport;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class CommandSupportTest extends TestCase
{
    public function testActorAndConflictCodes(): void
    {
        $definition = new InputDefinition([new InputOption('actor', null, InputOption::VALUE_REQUIRED)]);
        $resolver   = new class implements CurrencyKitActorResolverInterface {
            public ?ActorRef $actor = null;

            public function current(): ?ActorRef
            {
                return $this->actor;
            }
        };
        $support = new CommandSupport($resolver);
        self::assertNull($support->actor(new ArrayInput([], $definition), false));
        $resolver->actor = new ActorRef('token-user');
        self::assertSame('token-user', $support->actor(new ArrayInput([], $definition))?->value);
        self::assertSame('ops', $support->actor(new ArrayInput(['--actor' => 'ops'], $definition))?->value);

        $output = new BufferedOutput();
        $io     = new SymfonyStyle(new ArrayInput([]), $output);
        self::assertSame(CommandSupport::EXIT_TRANSIENT, CommandSupport::fail($io, new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 1, new LogicException('x'))));
        self::assertStringContainsString('CONFLICT_001', $output->fetch());
        self::assertSame(CommandSupport::EXIT_TRANSIENT, CommandSupport::fail($io, new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, new LogicException('x'), true)));
        self::assertStringContainsString('CONFLICT_002', $output->fetch());
        $interrupted = new SyncInterruptedException(new SyncReport(SourceKey::of('ecb'), SyncOutcome::Stored, null, ['2026-11-18']), new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 1, new LogicException('x')));
        self::assertSame(CommandSupport::EXIT_TRANSIENT, CommandSupport::fail($io, $interrupted));
        $text = $output->fetch();
        self::assertStringContainsString('CONFLICT_002', $text);
        self::assertStringContainsString('1 new day(s)', $text);
        self::assertSame(CommandSupport::EXIT_PERMANENT, CommandSupport::fail($io, new SyncInterruptedException(new SyncReport(SourceKey::of('ecb'), SyncOutcome::Stored), new RateSourceResponseException(RateSourceResponseException::REASON_MALFORMED))));
        self::assertSame(0, CommandSupport::ok());
        $this->expectException(LogicException::class);
        CommandSupport::fail($io, new LogicException('bug'));
    }

    public function testValidatorCurrencyCap(): void
    {
        $rates = [];
        for ($i = 0; $i < 401; ++$i) {
            $rates['C' . $i] = '1';
        }
        $validator = new BatchValidator(new BundledCurrencyCatalogue(), $this->createStub(RateStoreInterface::class));
        $this->expectException(RateSourceResponseException::class);
        $validator->validate(SourceKey::of('ecb'), new DayRates(RateDate::of('2026-11-19'), $rates), RateDate::of('2026-11-19'), null, 50);
    }
}
