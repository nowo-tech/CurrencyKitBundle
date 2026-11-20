<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Integration\Kernel;

use Nowo\CurrencyKitBundle\Tests\Support\FixtureHttp;
use Symfony\Component\HttpClient\Response\MockResponse;

use function str_contains;

/**
 * The kernel's HTTP: the recorded fixtures by URL. Requests are kept on the instance (a test service, never shared
 * between kernels).
 */
final class TestHttpFactory
{
    /** @var list<string> */
    public array $urls = [];

    /** @var array<string, MockResponse> URL fragment => response, consumed first */
    public array $overrides = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $this->urls[] = $url;
        foreach ($this->overrides as $fragment => $response) {
            if (str_contains($url, $fragment)) {
                return $response;
            }
        }
        foreach (['eurofxref-daily.xml' => 'ecb/eurofxref-daily.xml', 'eurofxref-hist-90d.xml' => 'ecb/eurofxref-hist-90d.xml', 'eurofxref-hist.xml' => 'ecb/eurofxref-hist-sample.xml', '/latest' => 'frankfurter/latest.json', '..' => 'frankfurter/range-2026-11-10..2026-11-14.json'] as $fragment => $fixture) {
            if (str_contains($url, $fragment)) {
                return new MockResponse(FixtureHttp::fixture($fixture));
            }
        }

        return new MockResponse('', ['http_code' => 404]);
    }
}
