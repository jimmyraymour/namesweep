<?php

/**
 * Per-suffix overrides. This is NOT an allowlist.
 *
 * Since M9 any delegated suffix is checkable — `SuffixResolver` falls through
 * to the IANA RDAP bootstrap (~1200 TLDs) and then to the IANA WHOIS directory
 * (whois.iana.org names the authoritative WHOIS host for everything else).
 * Entries here exist only to pin behaviour we have hand-verified, and to carry
 * marker sets that beat the generic ones.
 *
 * 'rdap'  → resolved via the IANA RDAP bootstrap (data.iana.org/rdap/dns.json).
 * 'whois' → direct WHOIS query on TCP 43 using the given host/port.
 *
 * Multi-label suffixes (com.do, co.uk) are supported: the suffix is looked up
 * whole first, then by its last label, because a registry serves its whole
 * namespace.
 *
 * NOTE on `.do` and `.ht`:
 *   - `.do` has no RDAP (absent from the IANA bootstrap) → WHOIS with
 *     markers filled in bootstrap.txt (verified live 2026-08-17).
 *   - `.ht` is RDAP-served: the registry's own public tool is an RDAP client
 *     and rdap.nic.ht answers (200 registered / 404 available, verified live
 *     2026-08-17). The WHOIS host (whois.nic.ht) is IPv6-only with port 43
 *     closed from both our networks, so it stays as a fallback only.
 */

return [
    /**
     * Generic WHOIS markers, used for any suffix whose own entry (and
     * bootstrap.txt) has none — which is every suffix discovered via the IANA
     * directory.
     *
     * `priority` deliberately puts `available` FIRST here, unlike the
     * per-TLD sets. Several registries echo the queried name back in a
     * not-found response: `.do` and `.com.do` answer an unregistered domain
     * with "Domain Name: foo.do / Domain Status: No Object Found". Matching
     * the generic `Domain Name:` marker first would report those as
     * registered. The not-found strings never appear in a real record, so
     * checking them first is the safe order.
     *
     * Verified 2026-08-19 against live registered + available responses for
     * .co .me .io .do .com.do .tv .ai .us .nl — 17/17 classified correctly.
     */
    '_default' => [
        'priority' => ['available', 'for_sale', 'parked', 'registered'],
        'markers'  => [
            'available'  => [
                'No match for',
                'Domain not found',
                'No Data Found',
                'No Object Found',
                'no entries found',
                'Status: free',
                'is available for registration',
                'No such domain',
                'object does not exist',
                'Not Registered',
                'NOT FOUND',
            ],
            'for_sale'   => ['This domain is for sale', 'Buy this domain'],
            'parked'     => [],
            'registered' => [
                'Domain Name:',
                'Registry Domain ID:',
                'Creation Date:',
                'Registrar:',
                'created:',
                'Name Server:',
                'Registrant',
            ],
        ],
        /**
         * Checked only after every marker misses, so that a genuine record is
         * never mistaken for an error. `.co` serves "Access to the Whois and
         * RDAP services is rate limited." as boilerplate inside every
         * successful response — a looser match here would break that TLD.
         */
        'rate_limit_markers' => [
            'ratelimit exceeded',
            'rate limit exceeded',
            'exceeded the maximum',
            'too many requests',
            'query rate exceeded',
            'quota exceeded',
        ],
    ],

    'com' => ['type' => 'rdap'],
    'net' => ['type' => 'rdap'],

    // .io is NOT served via the IANA RDAP bootstrap, so it falls back to WHOIS.
    // Markers verified live against whois.nic.io (2026-08-17).
    'io'  => [
        'type'    => 'rdap',
        'host'    => 'whois.nic.io',
        'port'    => 43,
        'markers' => [
            'available'  => ['Domain not found'],
            'registered' => ['Domain Name:', 'Registrar:'],
            'parked'     => [],
            'for_sale'   => [],
        ],
    ],

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
        'type'    => 'rdap',
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
