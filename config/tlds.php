<?php

/**
 * TLD → provider configuration.
 *
 * 'rdap'  → resolved via the IANA RDAP bootstrap (data.iana.org/rdap/dns.json).
 * 'whois' → direct WHOIS query on TCP 43 using the given host/port.
 *
 * NOTE on `.do` and `.ht`: the marker arrays are intentionally left EMPTY.
 * The owner fills them in after running a one-off WHOIS test on the VPS
 * (see bootstrap.txt at the repo root). Until then the WhoisClient logs the
 * raw response and returns `uncertain`. When the in-config array is empty,
 * the marker matcher reads the expected responses from bootstrap.txt.
 */

return [
    'com' => ['type' => 'rdap'],
    'net' => ['type' => 'rdap'],
    'io'  => ['type' => 'rdap'],

    'do'  => [
        'type'    => 'whois',
        'host'    => 'whois.nic.do',
        'port'    => 43,
        'markers' => [
            'available'  => [],
            'registered' => [],
            'parked'     => [],
            'for_sale'   => [],
        ],
    ],

    'ht'  => [
        'type'    => 'whois',
        'host'    => 'whois.nic.ht',
        'port'    => 43,
        'markers' => [
            'available'  => [],
            'registered' => [],
            'parked'     => [],
            'for_sale'   => [],
        ],
    ],
];
