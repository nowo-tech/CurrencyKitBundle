<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Http;

use Nowo\CurrencyKitBundle\Application\CurrencyKitSettings;
use Nowo\CurrencyKitBundle\Domain\Exception\OutboundDisabledException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceUnavailableException;
use Nowo\CurrencyKitBundle\Domain\Port\OutboundFetcherInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchedBody;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchLimits;
use Nowo\OutboundUrlGuardBundle\Exception\UnsafeOutboundUrlException;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function strlen;

/**
 * The only HTTP code of the bundle (contract section 7.2).
 *
 * 1. `outbound.enabled: false` ⇒ {@see OutboundDisabledException} before anything else (no DNS either).
 * 2. The outbound URL guard pins the resolved public address (`allowPrivate` only for a mirror that opted in; cloud
 *    metadata stays blocked); a refusal is transient `guard_refused`.
 * 3. No redirects (a 3xx is `redirect_refused`), idle timeout, total duration, identifying User-Agent.
 * 4. The body is streamed and aborted past `maxBytes` decoded bytes (a compressed bomb is caught).
 * 5. 4xx ⇒ permanent; 5xx, timeout and transport errors ⇒ transient, retried `$attempts − 1` times after 2 s then 8 s.
 *
 * Nothing survives a call: no response, stream or handle is kept (worker mode).
 */
final readonly class GuardedHttpFetcher implements OutboundFetcherInterface
{
    /** Pauses between attempts, in seconds. */
    private const BACKOFF = [2, 8];

    /**
     * @param string $userAgent `nowo-currency-kit/<version> (+<contact_url>)`
     */
    public function __construct(
        private HttpClientInterface $client,
        private OutboundUrlGuard $guard,
        private ClockInterface $clock,
        private CurrencyKitSettings $settings,
        private string $userAgent,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $url, FetchLimits $limits, bool $allowPrivate = false, int $attempts = 1): FetchedBody
    {
        if (!$this->settings->outboundEnabled) {
            throw new OutboundDisabledException();
        }
        for ($attempt = 1;; ++$attempt) {
            try {
                return $this->once($url, $limits, $allowPrivate);
            } catch (RateSourceUnavailableException $e) {
                if ($attempt >= $attempts) {
                    throw $e;
                }
                $this->clock->sleep(self::BACKOFF[$attempt - 1] ?? 8);
            }
        }
    }

    /**
     * One guarded GET.
     *
     * @throws RateSourceUnavailableException
     * @throws RateSourceResponseException
     */
    private function once(string $url, FetchLimits $limits, bool $allowPrivate): FetchedBody
    {
        try {
            $options = $this->guard->httpClientOptions($url, $allowPrivate);
        } catch (UnsafeOutboundUrlException $e) {
            throw new RateSourceUnavailableException(RateSourceUnavailableException::REASON_GUARD_REFUSED, $e);
        }
        $options += [
            'max_redirects' => 0,
            'timeout'       => $limits->timeout,
            'max_duration'  => $limits->maxDuration,
            'headers'       => [
                'User-Agent' => $this->userAgent,
                'Accept'     => $limits->format === 'json' ? 'application/json' : 'application/xml',
            ],
        ];

        $body = '';
        try {
            $response = $this->client->request('GET', $url, $options);
            foreach ($this->client->stream($response) as $chunk) {
                if ($chunk->isFirst()) {
                    $status = $response->getStatusCode();
                    if ($status >= 300) {
                        $response->cancel();

                        throw match (true) {
                            $status < 400 => new RateSourceUnavailableException(RateSourceUnavailableException::REASON_REDIRECT_REFUSED),
                            $status < 500 => new RateSourceResponseException(RateSourceResponseException::REASON_CLIENT_ERROR),
                            default       => new RateSourceUnavailableException(RateSourceUnavailableException::REASON_SERVER_ERROR),
                        };
                    }
                }
                $body .= $chunk->getContent();
                if (strlen($body) > $limits->maxBytes) {
                    $response->cancel();

                    throw new RateSourceResponseException(RateSourceResponseException::REASON_TOO_LARGE, strlen($body));
                }
            }
        } catch (TransportExceptionInterface $e) {
            throw new RateSourceUnavailableException(RateSourceUnavailableException::REASON_TRANSPORT, $e);
        }

        return new FetchedBody($body);
    }
}
