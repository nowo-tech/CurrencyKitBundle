<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Support;

use Nowo\OutboundUrlGuardBundle\Dns\HostnameDnsLookup;

use function str_ends_with;

use const DNS_A;

/**
 * DNS seam of the URL guard for tests: no lookup leaves the process. `*.private.test` resolves to a private address,
 * `metadata.test` to the cloud metadata address, everything else to a documentation public address.
 */
final class FakeDns extends HostnameDnsLookup
{
    public function __construct()
    {
        parent::__construct(1.0, '');
    }

    public function dnsGetRecord(string $hostname, int $type): array
    {
        if ($type !== DNS_A) {
            return [];
        }
        $ip = match (true) {
            str_ends_with($hostname, '.private.test') => '10.0.0.5',
            $hostname === 'metadata.test'             => '169.254.169.254',
            default                                   => '93.184.216.34',
        };

        return [['ip' => $ip]];
    }

    public function hostByNameL(string $hostname): array|false
    {
        return false;
    }
}
