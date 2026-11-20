<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Infrastructure;

use Nowo\CurrencyKitBundle\Application\CurrencyKitSettings;
use Nowo\CurrencyKitBundle\Domain\Exception\OutboundDisabledException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceUnavailableException;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchLimits;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Infrastructure\Http\GuardedHttpFetcher;
use Nowo\CurrencyKitBundle\Tests\Support\FakeDns;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_shift;
use function str_repeat;

final class GuardedHttpFetcherTest extends TestCase
{
    private MockClock $clock;

    /** @var list<array<mixed>> */
    private array $options = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-19 17:00:00');
    }

    private function fetcher(HttpClientInterface $client, bool $enabled = true): GuardedHttpFetcher
    {
        return new GuardedHttpFetcher($client, new OutboundUrlGuard(false, true, new FakeDns()), $this->clock, new CurrencyKitSettings(new SourceKey('ecb'), outboundEnabled: $enabled), 'nowo-currency-kit/test (+https://example.test)');
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->options[] = $options;

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });
    }

    public function testGuardedGetWithPinsAndHeaders(): void
    {
        $body = $this->fetcher($this->client([new MockResponse('<xml/>')]))->get('https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml', new FetchLimits(100, 10.0, 30.0));
        self::assertSame('<xml/>', $body->content);
        $options = $this->options[0];
        self::assertSame(0, $options['max_redirects']);
        self::assertIsArray($options['resolve']);
        self::assertSame('93.184.216.34', $options['resolve']['www.ecb.europa.eu'] ?? null, 'pinned');
        self::assertIsArray($options['headers']);
        self::assertContains('User-Agent: nowo-currency-kit/test (+https://example.test)', $options['headers']);
        self::assertContains('Accept: application/xml', $options['headers']);
        $this->fetcher($this->client([new MockResponse('{}')]))->get('https://api.mirror.test/latest', new FetchLimits(100, 10.0, 30.0, 'json'));
        self::assertIsArray($this->options[1]['headers']);
        self::assertContains('Accept: application/json', $this->options[1]['headers']);
    }

    public function testFailuresAndRetries(): void
    {
        $limits = new FetchLimits(100, 10.0, 30.0);
        $cases  = [
            'redirect'     => [[new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location: https://evil.test/']])], RateSourceUnavailableException::class, RateSourceUnavailableException::REASON_REDIRECT_REFUSED],
            'client error' => [[new MockResponse('', ['http_code' => 404])], RateSourceResponseException::class, RateSourceResponseException::REASON_CLIENT_ERROR],
            'server error' => [[new MockResponse('', ['http_code' => 503])], RateSourceUnavailableException::class, RateSourceUnavailableException::REASON_SERVER_ERROR],
            'transport'    => [[new MockResponse('', ['error' => 'host unreachable'])], RateSourceUnavailableException::class, RateSourceUnavailableException::REASON_TRANSPORT],
            'too large'    => [[new MockResponse(str_repeat('x', 101))], RateSourceResponseException::class, RateSourceResponseException::REASON_TOO_LARGE],
            'bomb chunks'  => [[new MockResponse([str_repeat('x', 60), str_repeat('x', 60)])], RateSourceResponseException::class, RateSourceResponseException::REASON_TOO_LARGE],
        ];
        foreach ($cases as $case => [$responses, $class, $reason]) {
            try {
                $this->fetcher($this->client($responses))->get('https://www.ecb.europa.eu/x.xml', $limits);
                self::fail('accepted ' . $case);
            } catch (RateSourceUnavailableException|RateSourceResponseException $e) {
                self::assertSame($class, $e::class, $case);
                self::assertSame($reason, $e->reason, $case);
            }
        }

        $start = $this->clock->now()->getTimestamp();
        $body  = $this->fetcher($this->client([new MockResponse('', ['http_code' => 502]), new MockResponse('', ['http_code' => 503]), new MockResponse('ok')]))->get('https://www.ecb.europa.eu/x.xml', $limits, false, 3);
        self::assertSame('ok', $body->content);
        self::assertSame(10, $this->clock->now()->getTimestamp() - $start, 'backoff 2 s then 8 s');
        try {
            $this->fetcher($this->client([new MockResponse('', ['http_code' => 404]), new MockResponse('ok')]))->get('https://www.ecb.europa.eu/x.xml', $limits, false, 3);
            self::fail('a permanent failure was retried');
        } catch (RateSourceResponseException) {
        }
        $this->expectException(RateSourceUnavailableException::class);
        $this->fetcher($this->client([]))->get('https://www.ecb.europa.eu/x.xml', $limits, false, 4);
    }

    public function testGuardAndDisabled(): void
    {
        $limits = new FetchLimits(100, 10.0, 30.0);
        foreach (['https://metadata.test/latest', 'ftp://www.ecb.europa.eu/x', 'https://mirror.private.test/v1/latest', 'https://10.0.0.1/x'] as $url) {
            try {
                $this->fetcher($this->client([new MockResponse('ok')]))->get($url, $limits);
                self::fail('allowed ' . $url);
            } catch (RateSourceUnavailableException $e) {
                self::assertSame(RateSourceUnavailableException::REASON_GUARD_REFUSED, $e->reason);
            }
        }
        self::assertSame('ok', $this->fetcher($this->client([new MockResponse('ok')]))->get('https://mirror.private.test/v1/latest', $limits, true)->content, 'private network by flag');
        try {
            $this->fetcher($this->client([new MockResponse('ok')]))->get('https://metadata.test/latest', $limits, true);
            self::fail('cloud metadata allowed');
        } catch (RateSourceUnavailableException) {
        }
        $this->options = [];
        try {
            $this->fetcher($this->client([new MockResponse('ok')]), false)->get('https://www.ecb.europa.eu/x.xml', $limits);
            self::fail('fetched while disabled');
        } catch (OutboundDisabledException) {
        }
        self::assertSame([], $this->options, 'no request when disabled');
    }
}
