<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Support;

use function sprintf;

/**
 * Builds ECB eurofxref XML in the recorded files' shape (for dates the recordings do not cover).
 */
final class EcbXml
{
    /**
     * @param array<string, array<string, string>> $days date => code => rate
     */
    public static function of(array $days): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><gesmes:subject>Reference rates</gesmes:subject><gesmes:Sender><gesmes:name>European Central Bank</gesmes:name></gesmes:Sender><Cube>';
        foreach ($days as $date => $rates) {
            $xml .= sprintf('<Cube time="%s">', $date);
            foreach ($rates as $code => $rate) {
                $xml .= sprintf('<Cube currency="%s" rate="%s"/>', $code, $rate);
            }
            $xml .= '</Cube>';
        }

        return $xml . '</Cube></gesmes:Envelope>';
    }
}
