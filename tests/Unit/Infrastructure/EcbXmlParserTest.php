<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Infrastructure;

use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Rate\DayRates;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Ecb\EcbXmlParser;
use Nowo\CurrencyKitBundle\Tests\Support\EcbXml;
use Nowo\CurrencyKitBundle\Tests\Support\FixtureHttp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function clearstatcache;
use function dirname;
use function file_put_contents;
use function iterator_to_array;
use function sprintf;
use function str_repeat;
use function substr;
use function sys_get_temp_dir;
use function tempnam;

final class EcbXmlParserTest extends TestCase
{
    public function testDailyFileValuesStayStrings(): void
    {
        $days = iterator_to_array((new EcbXmlParser())->parseString(FixtureHttp::fixture('ecb/eurofxref-daily.xml'), 10), false);
        self::assertCount(1, $days);
        self::assertSame('2026-11-19', $days[0]->date->value);
        self::assertCount(29, $days[0]->rates);
        self::assertSame('1.0992', $days[0]->rates['USD']);
        self::assertSame('4.4170', $days[0]->rates['PLN'], 'byte for byte, trailing zero kept');
        self::assertSame('20073.10', $days[0]->rates['IDR']);
    }

    public function testFullRecordedNinetyDayFileAndHistorySample(): void
    {
        $days = iterator_to_array((new EcbXmlParser())->parseString(FixtureHttp::fixture('ecb/eurofxref-hist-90d-full.xml'), 100), false);
        self::assertCount(64, $days);
        $sample = iterator_to_array((new EcbXmlParser())->parseString(FixtureHttp::fixture('ecb/eurofxref-hist-sample.xml'), 100), false);
        $dates  = array_map(static fn (DayRates $d): string => $d->date->value, $sample);
        self::assertContains('1999-01-04', $dates);
        self::assertArrayHasKey('CYP', $sample[5]->rates);
    }

    public function testParsesALocalFile(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'cyk');
        file_put_contents($file, FixtureHttp::fixture('ecb/eurofxref-hist-90d.xml'));
        self::assertCount(10, iterator_to_array((new EcbXmlParser())->parseFile($file, 1_000_000, 100), false));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostile(): iterable
    {
        $ns = 'xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"';
        yield 'billion laughs' => ['<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;">]><Envelope ' . $ns . '><Cube><Cube time="2026-11-19"><Cube currency="USD" rate="&lol2;"/></Cube></Cube></Envelope>', RateSourceResponseException::REASON_DOCTYPE];
        yield 'external entity' => ['<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><Envelope ' . $ns . '><Cube><Cube time="2026-11-19"><Cube currency="USD" rate="&x;"/></Cube></Cube></Envelope>', RateSourceResponseException::REASON_DOCTYPE];
        yield 'plain doctype' => ['<?xml version="1.0"?><!DOCTYPE html><Envelope ' . $ns . '/>', RateSourceResponseException::REASON_DOCTYPE];
        yield 'deep nesting' => ['<a>' . str_repeat('<b>', 12) . str_repeat('</b>', 12) . '</a>', RateSourceResponseException::REASON_LIMIT];
        yield 'huge attribute' => ['<Envelope ' . $ns . '><Cube><Cube time="2026-11-19"><Cube currency="USD" rate="' . str_repeat('1', 40) . '"/></Cube></Cube></Envelope>', RateSourceResponseException::REASON_LIMIT];
        yield 'truncated' => [substr(FixtureHttp::fixture('ecb/eurofxref-daily.xml'), 0, 600), RateSourceResponseException::REASON_MALFORMED];
        yield 'not xml' => ['{"rates": {}}', RateSourceResponseException::REASON_MALFORMED];
        yield 'empty' => ['', RateSourceResponseException::REASON_MALFORMED];
        yield 'no day' => ['<Envelope ' . $ns . '><Cube/></Envelope>', RateSourceResponseException::REASON_MALFORMED];
        yield 'empty day' => ['<Envelope ' . $ns . '><Cube><Cube time="2026-11-19"/></Cube></Envelope>', RateSourceResponseException::REASON_MALFORMED];
        yield 'nested day' => ['<Envelope ' . $ns . '><Cube><Cube time="2026-11-19"><Cube time="2026-11-18"><Cube currency="USD" rate="1"/></Cube></Cube></Cube></Envelope>', RateSourceResponseException::REASON_MALFORMED];
        yield 'rate outside a day' => ['<Envelope ' . $ns . '><Cube><Cube currency="USD" rate="1"/></Cube></Envelope>', RateSourceResponseException::REASON_MALFORMED];
        yield 'missing rate' => ['<Envelope ' . $ns . '><Cube><Cube time="2026-11-19"><Cube currency="USD"/></Cube></Cube></Envelope>', RateSourceResponseException::REASON_MALFORMED];
        yield 'duplicate currency' => ['<Envelope ' . $ns . '><Cube><Cube time="2026-11-19"><Cube currency="USD" rate="1"/><Cube currency="USD" rate="2"/></Cube></Cube></Envelope>', RateSourceResponseException::REASON_MALFORMED];
        yield 'bad date' => [EcbXml::of(['2026-02-30' => ['USD' => '1']]), RateSourceResponseException::REASON_INVALID_VALUE];
    }

    #[DataProvider('hostile')]
    public function testHostileInputIsRefused(string $xml, string $reason): void
    {
        try {
            iterator_to_array((new EcbXmlParser())->parseString($xml, 10), false);
            self::fail('accepted');
        } catch (RateSourceResponseException $e) {
            // A DOCTYPE is refused as soon as it is read; libxml may already fail on it (no network, no DTD load).
            $expected = $reason === RateSourceResponseException::REASON_DOCTYPE ? [RateSourceResponseException::REASON_DOCTYPE, RateSourceResponseException::REASON_MALFORMED] : [$reason];
            self::assertContains($e->reason, $expected);
            self::assertStringNotContainsString('passwd', $e->getMessage());
        }
    }

    public function testCapsOnDaysCurrenciesAndFiles(): void
    {
        try {
            iterator_to_array((new EcbXmlParser())->parseString(FixtureHttp::fixture('ecb/eurofxref-hist-90d.xml'), 3), false);
            self::fail('accepted');
        } catch (RateSourceResponseException $e) {
            self::assertSame(RateSourceResponseException::REASON_LIMIT, $e->reason);
        }
        $rates = [];
        for ($i = 0; $i < 401; ++$i) {
            $rates[sprintf('C%02d', $i)] = '1';
        }
        try {
            iterator_to_array((new EcbXmlParser())->parseString(EcbXml::of(['2026-11-19' => $rates]), 3), false);
            self::fail('accepted');
        } catch (RateSourceResponseException $e) {
            self::assertSame(RateSourceResponseException::REASON_LIMIT, $e->reason);
        }
        $file = (string) tempnam(sys_get_temp_dir(), 'cyk');
        file_put_contents($file, FixtureHttp::fixture('ecb/eurofxref-daily.xml'));
        foreach ([[$file, 10, RateSourceResponseException::REASON_TOO_LARGE], [dirname($file) . '/missing.xml', 10, RateSourceResponseException::REASON_UNREADABLE]] as [$path, $max, $reason]) {
            try {
                (new EcbXmlParser())->parseFile($path, $max, 10);
                self::fail('accepted');
            } catch (RateSourceResponseException $e) {
                self::assertSame($reason, $e->reason);
            }
        }
        file_put_contents($file, '');
        clearstatcache();
        $this->expectException(RateSourceResponseException::class);
        $this->expectExceptionMessage('cannot be used');
        (new EcbXmlParser())->parseFile($file, 10, 10);
    }
}
