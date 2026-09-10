<?php

/**
 * Prices for suffixes the automatic source cannot cover.
 *
 * `TldPricing` reads Porkbun's public pricing endpoint (907 suffixes, no key
 * required). Anything Porkbun does not sell falls through to this file.
 *
 * ── Fill these in yourself ──────────────────────────────────────────────────
 * The Caribbean ccTLDs below are the ones you actually care about and the ones
 * no keyless source prices. Their registries do not publish a machine-readable
 * (or scrapable) price list — nic.do returns a page with no pricing section,
 * and registro.do does not resolve — so the numbers are left null rather than
 * guessed. A report that says "no price available" is useful; a report that
 * quotes an invented figure is not.
 *
 * To fill one in, get a quote from a registrar that actually sells the suffix,
 * then set `registration` / `renewal` with the `currency`, the `source` you got
 * it from, and the `verified_on` date. The date is surfaced in the report's
 * price source (e.g. `nic.do (as of 2026-08-19)`) so a stale hand-entered
 * figure always says how old it is.
 *
 * Same pattern as `bootstrap.txt` for WHOIS markers: structure now, real values
 * when you have them, and an honest gap until then.
 */

return [
    'suffixes' => [
        'do' => [
            'registration' => null,
            'renewal'      => null,
            'transfer'     => null,
            'currency'     => 'USD',
            'source'       => 'nic.do',
            'verified_on'  => null,
        ],

        'com.do' => [
            'registration' => null,
            'renewal'      => null,
            'transfer'     => null,
            'currency'     => 'USD',
            'source'       => 'nic.do',
            'verified_on'  => null,
        ],

        'ht' => [
            'registration' => null,
            'renewal'      => null,
            'transfer'     => null,
            'currency'     => 'USD',
            'source'       => 'nic.ht',
            'verified_on'  => null,
        ],
    ],
];
