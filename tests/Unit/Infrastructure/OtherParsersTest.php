<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Infrastructure;

use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Port\RateFileReaderInterface;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Csv\CsvRateParser;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Ecb\EcbXmlParser;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Frankfurter\FrankfurterJsonParser;
use Nowo\CurrencyKitBundle\Infrastructure\Source\LocalRateFileReader;
use Nowo\CurrencyKitBundle\Tests\Support\FixtureHttp;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function iterator_to_array;
use function sprintf;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;

final class OtherParsersTest extends TestCase
{
    public function testFrankfurterRecordedResponses(): void
    {
        $parser = new FrankfurterJsonParser();
        $latest = $parser->parse(FixtureHttp::fixture('frankfurter/latest.json'), false, 10);
        self::assertSame('2026-11-19', $latest[0]->date->value);
        self::assertSame('1.0992', $latest[0]->rates['USD']);
        self::assertSame('7.328', $latest[0]->rates['CNY'], 'shortest round-trip string');
        self::assertSame('137.2', $latest[0]->rates['ISK']);
        $range = $parser->parse(FixtureHttp::fixture('frankfurter/range-2026-11-10..2026-11-14.json'), false, 10);
        self::assertCount(4, $range);
        self::assertSame('2026-11-13', $parser->parse(FixtureHttp::fixture('frankfurter/date-2026-11-13.json'), false, 10)[0]->date->value);
    }

    public function testFrankfurterRebaseAndRefusals(): void
    {
        $parser  = new FrankfurterJsonParser();
        $rebased = $parser->parse('{"amount":1,"base":"USD","date":"2026-11-19","rates":{"EUR":0.5,"GBP":0.25,"JPY":100}}', true, 10);
        self::assertSame(['USD' => '2', 'GBP' => '0.5', 'JPY' => '200'], $rebased[0]->rates);
        self::assertSame(['USD' => '137'], $parser->parse('{"amount":1,"base":"EUR","date":"2026-11-19","rates":{"USD":137}}', false, 10)[0]->rates);
        foreach ([
            '{not json'                                                                                                         => RateSourceResponseException::REASON_MALFORMED,
            '[]'                                                                                                                => RateSourceResponseException::REASON_MALFORMED,
            '{"amount":1,"base":"EUR"}'                                                                                         => RateSourceResponseException::REASON_MALFORMED,
            '{"amount":2,"base":"EUR","date":"2026-11-19","rates":{}}'                                                          => RateSourceResponseException::REASON_WRONG_BASE,
            '{"amount":1,"base":"USD","date":"2026-11-19","rates":{"GBP":1}}'                                                   => RateSourceResponseException::REASON_WRONG_BASE,
            '{"amount":1,"base":"EUR","date":20261119,"rates":{"USD":1}}'                                                       => RateSourceResponseException::REASON_MALFORMED,
            '{"amount":1,"base":"EUR","date":"2026-13-01","rates":{"USD":1}}'                                                   => RateSourceResponseException::REASON_INVALID_VALUE,
            '{"amount":1,"base":"EUR","date":"2026-11-19","rates":{"USD":"1.1"}}'                                               => RateSourceResponseException::REASON_INVALID_VALUE,
            '{"amount":1,"base":"EUR","date":"2026-11-19","rates":{"USD":0.00001}}'                                             => RateSourceResponseException::REASON_INVALID_VALUE,
            '{"amount":1,"base":"EUR","start_date":"a","end_date":"b","rates":{"2026-11-10":{"USD":1},"2026-11-11":{"USD":1}}}' => RateSourceResponseException::REASON_LIMIT,
            '{"a":{"b":{"c":{"d":{"e":{"f":{"g":{"h":{"i":1}}}}}}}}}'                                                           => RateSourceResponseException::REASON_MALFORMED,
        ] as $json => $reason) {
            try {
                $parser->parse($json, false, 1);
                self::fail('accepted ' . $json);
            } catch (RateSourceResponseException $e) {
                self::assertSame($reason, $e->reason, $json);
            }
        }
        foreach (['{"amount":1,"base":"USD","date":"2026-11-19","rates":{"EUR":0}}', '{"amount":1,"base":"USD","date":"2026-11-19","rates":{"EUR":-1}}'] as $json) {
            try {
                $parser->parse($json, true, 1);
                self::fail('accepted ' . $json);
            } catch (RateSourceResponseException $e) {
                self::assertSame(RateSourceResponseException::REASON_INVALID_VALUE, $e->reason);
            }
        }
        $this->assertSame(['USD' => '0.30000000000000004'], $parser->parse('{"amount":1,"base":"EUR","date":"2026-11-19","rates":{"USD":0.30000000000000004}}', false, 1)[0]->rates);
    }

    public function testCsv(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'cyk');
        file_put_contents($file, "date,currency,rate\n2026-11-18,USD,1.0968\n2026-11-18,GBP,0.8329\n\n2026-11-19,USD,1.0992\n");
        $days = iterator_to_array((new CsvRateParser())->parseFile($file, 1000, 10), false);
        self::assertCount(2, $days);
        self::assertSame(['USD' => '1.0968', 'GBP' => '0.8329'], $days[0]->rates);
        self::assertSame('=1+1', iterator_to_array((new CsvRateParser())->parseFile($this->write("date,currency,rate\n2026-11-19,USD,=1+1\n"), 1000, 10), false)[0]->rates['USD'], 'never evaluated');
        foreach ([
            "currency,date,rate\n"                                                       => RateSourceResponseException::REASON_MALFORMED,
            "date,currency,rate\n"                                                       => RateSourceResponseException::REASON_MALFORMED,
            "date,currency,rate\n2026-11-19,USD\n"                                       => RateSourceResponseException::REASON_MALFORMED,
            "date,currency,rate\n2026-11-19,USD," . str_repeat('1', 40) . "\n"           => RateSourceResponseException::REASON_MALFORMED,
            "date,currency,rate\n2026-11-19,USD,1\n2026-11-19,USD,2\n"                   => RateSourceResponseException::REASON_MALFORMED,
            "date,currency,rate\n2026-19-19,USD,1\n"                                     => RateSourceResponseException::REASON_INVALID_VALUE,
            "date,currency,rate\n2026-11-18,USD,1\n2026-11-19,USD,1\n"                   => RateSourceResponseException::REASON_LIMIT,
            "date,currency,rate\n2026-11-18,USD,1\n2026-11-19,USD,1\n2026-11-18,USD,1\n" => RateSourceResponseException::REASON_DUPLICATE_DATE,
        ] as $csv => $reason) {
            try {
                iterator_to_array((new CsvRateParser())->parseFile($this->write($csv), 1000, $reason === RateSourceResponseException::REASON_LIMIT ? 1 : 10), false);
                self::fail('accepted ' . $csv);
            } catch (RateSourceResponseException $e) {
                self::assertSame($reason, $e->reason, $csv);
            }
        }
        $rows = "date,currency,rate\n";
        for ($i = 0; $i < 401; ++$i) {
            $rows .= sprintf("2026-11-19,C%03d,1\n", $i);
        }
        try {
            iterator_to_array((new CsvRateParser())->parseFile($this->write($rows), 100000, 10), false);
            self::fail('accepted');
        } catch (RateSourceResponseException $e) {
            self::assertSame(RateSourceResponseException::REASON_LIMIT, $e->reason);
        }
        foreach ([[$this->write('x'), 0, RateSourceResponseException::REASON_TOO_LARGE], ['/nonexistent/file.csv', 10, RateSourceResponseException::REASON_UNREADABLE]] as [$path, $max, $reason]) {
            try {
                (new CsvRateParser())->parseFile($path, $max, 10);
                self::fail('accepted');
            } catch (RateSourceResponseException $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    public function testFileReaderFormats(): void
    {
        $reader = new LocalRateFileReader(new EcbXmlParser(), new CsvRateParser(), 1_000_000);
        $xml    = $this->write(FixtureHttp::fixture('ecb/eurofxref-daily.xml'));
        self::assertCount(1, iterator_to_array($reader->read($xml, RateFileReaderInterface::FORMAT_ECB_XML, 10), false));
        self::assertCount(1, iterator_to_array($reader->read($this->write("date,currency,rate\n2026-11-19,USD,1\n"), RateFileReaderInterface::FORMAT_CSV, 10), false));
        $this->expectException(RateSourceResponseException::class);
        $reader->read($xml, 'xlsx', 10);
    }

    private function write(string $content): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'cyk');
        file_put_contents($file, $content);

        return $file;
    }
}
