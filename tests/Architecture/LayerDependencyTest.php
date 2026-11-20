<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function preg_match;
use function preg_match_all;
use function str_starts_with;

/**
 * Layer rules (contract section 4, REQ-ARCH-001): the domain imports no framework, Doctrine, HTTP or brick/math class
 * (brick/math only inside Domain\Money\Decimal, never in a signature); the application imports ports, domain and PSR
 * only; nothing in src/ calls persist, flush, clear or resetManager; the change log has no UPDATE or DELETE path.
 */
final class LayerDependencyTest extends TestCase
{
    /**
     * @return array<string, string> path => code
     */
    private function files(string $dir): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src/' . $dir)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function imports(string $code): array
    {
        preg_match_all('/^use (?!function |const )([A-Za-z0-9_\\\\]+)/m', $code, $matches);

        return $matches[1];
    }

    public function testDomainIsPlainPhp(): void
    {
        foreach ($this->files('Domain') as $path => $code) {
            foreach ($this->imports($code) as $import) {
                if (str_starts_with($import, 'Brick\\Math\\')) {
                    self::assertStringEndsWith('Domain/Money/Decimal.php', $path, 'brick/math only inside Decimal');

                    continue;
                }
                self::assertMatchesRegularExpression('/^(Nowo\\\\CurrencyKitBundle\\\\Domain\\\\|DateTime|DateTimeImmutable|DateTimeInterface|DateTimeZone|Stringable|Throwable|RuntimeException|Generator)/', $import, $path);
            }
        }
    }

    public function testApplicationDependsOnPortsOnly(): void
    {
        foreach ($this->files('Application') as $path => $code) {
            foreach ($this->imports($code) as $import) {
                self::assertMatchesRegularExpression('/^(Nowo\\\\CurrencyKitBundle\\\\(Domain|Application)\\\\|Psr\\\\(Clock|Log|EventDispatcher)\\\\|DateTime|Throwable)/', $import, $path);
            }
        }
    }

    public function testNoEntityManagerWritesAndAppendOnlyChanges(): void
    {
        foreach ($this->files('') as $path => $code) {
            self::assertSame(0, preg_match('/->(persist|flush|clear|resetManager)\(/', $code), $path);
        }
        $store = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Dbal/DbalRateStore.php');
        self::assertSame(0, preg_match('/(update|delete)\(\$this->tables->change\(\)|(UPDATE|DELETE FROM) %s[^\']*\', \$this->tables->change\(\)/', $store));
    }
}
