<?php

/**
 * Trademark markets and the provider that serves each one.
 */

return [
    // `cc_tld` — the country-code suffix of the jurisdiction. Usually the same
    // as the market id, but not always (DR is the market id, .do the suffix).
    // The alternatives set uses cc_tld, not the market key, so "check the
    // market's suffix too" points at a real suffix.
    'dr' => ['provider' => 'onapi', 'jurisdiction' => 'Dominican Republic', 'cc_tld' => 'do'],
    'ht' => ['provider' => 'hti',   'jurisdiction' => 'Haiti',             'cc_tld' => 'ht'],
    'us' => ['provider' => 'uspto', 'jurisdiction' => 'United States',     'cc_tld' => 'us'],
];
