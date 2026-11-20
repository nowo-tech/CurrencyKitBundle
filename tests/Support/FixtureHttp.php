<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_shift;
use function file_get_contents;
use function is_array;
use function str_contains;

/**
 * A MockHttpClient serving the recorded fixtures by URL; every request is kept (URL and options) for assertions.
 */
final class FixtureHttp
{
    /** @var list<array{url: string, options: array<mixed>}> */
    public array $requests = [];

    public MockHttpClient $client;

    /**
     * @param array<string, list<MockResponse>|MockResponse|string> $routes URL fragment => fixture path (relative to
     *                                                                      tests/Fixtures), a response, or a queue
     */
    public function __construct(private array $routes = [])
    {
        $this->client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['url' => $url, 'options' => $options];
            foreach ($this->routes as $fragment => $route) {
                if (!str_contains($url, $fragment)) {
                    continue;
                }
                if (is_array($route)) {
                    $next                    = array_shift($route) ?? new MockResponse('', ['http_code' => 500]);
                    $this->routes[$fragment] = $route === [] ? $next : $route;

                    return $next;
                }

                return $route instanceof MockResponse ? $route : new MockResponse(self::fixture($route));
            }

            return new MockResponse('not routed', ['http_code' => 404]);
        });
    }

    /**
     * ECB daily, 90-day and history files from the default fixtures.
     */
    public static function ecb(string $daily = 'ecb/eurofxref-daily.xml', string $last90 = 'ecb/eurofxref-hist-90d.xml', string $history = 'ecb/eurofxref-hist-sample.xml'): self
    {
        return new self(['eurofxref-daily.xml' => $daily, 'eurofxref-hist-90d.xml' => $last90, 'eurofxref-hist.xml' => $history]);
    }

    /**
     * The content of a fixture.
     */
    public static function fixture(string $path): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/' . $path);
    }
}
