<?php

/**
 * Nameserver operators that indicate a domain is parked or listed for sale.
 *
 * Why DNS rather than HTML: the aftermarket landing pages we care about are
 * JavaScript-rendered. Afternic, DAN and NameFind all answer a bare HTTPS
 * request with a 114-byte document —
 * `<script>window.onload=function(){window.location.href="/lander"}</script>`
 * — containing none of the signatures `ParkedPageDetector` looks for. Fetching
 * the page therefore yields `available` for a domain that is actively for sale.
 * The delegation, by contrast, is unambiguous and costs one DNS query.
 *
 * Matching is on the nameserver's registrable domain (last two labels), so
 * `ns1.afternic.com` and `dns2.afternic.com` both match `afternic.com`.
 *
 * `status` is what a match implies:
 *   - `for_sale` — the operator is a marketplace or broker; the domain is
 *     being actively sold.
 *   - `parked`   — the operator is an ad-monetisation parking service. It may
 *     also be for sale, but the delegation alone does not prove it.
 *
 * `verified` records whether we confirmed this operator against live data on
 * 2026-08-19 rather than from general knowledge. Unverified entries are still
 * safe — every operator here exists only to hold non-operating domains — but
 * the flag says which ones we have actually seen.
 *
 * DELIBERATELY EXCLUDED: general-purpose registrar and CDN nameservers, even
 * though parked domains often use them. `domaincontrol.com` (GoDaddy),
 * `googledomains.com`, `cloudflare.com`, `registrar-servers.com` (Namecheap),
 * `name.com`, `porkbun.com`, `spaceship.net`, `share-dns.com` and
 * `power-dns.com` all serve real, operating sites in volume. Matching them
 * would report live businesses as for sale — the one error this module must
 * never make.
 */

return [
    /**
     * Nameserver registrable-domain => how to classify the domain.
     *
     * Frequencies below are from a 149-domain sample of randomly generated,
     * registered 5–6 letter .com names probed on 2026-08-19 — roughly 40% of
     * which sit on aftermarket infrastructure.
     */
    'nameservers' => [
        // ── Marketplaces and brokers ────────────────────────────────────────
        'afternic.com'                            => ['status' => 'for_sale', 'label' => 'Afternic',      'verified' => true],  // 20/149
        'namebrightdns.com'                       => ['status' => 'for_sale', 'label' => 'HugeDomains',   'verified' => true],  // 19/149
        'hugedomainsdns.com'                      => ['status' => 'for_sale', 'label' => 'HugeDomains',   'verified' => true],  // 3/149
        'eftydns.com'                             => ['status' => 'for_sale', 'label' => 'Efty',          'verified' => true],  // 7/149
        'abovedomains.com'                        => ['status' => 'for_sale', 'label' => 'Above.com',     'verified' => true],  // 5/149
        'dan.com'                                 => ['status' => 'for_sale', 'label' => 'DAN.com',       'verified' => true],
        'namefind.com'                            => ['status' => 'for_sale', 'label' => 'NameFind',      'verified' => true],
        'domain-is-4-sale-at-domainmarket.com'    => ['status' => 'for_sale', 'label' => 'DomainMarket',  'verified' => true],
        'atom.com'                                => ['status' => 'for_sale', 'label' => 'Atom',          'verified' => true],  // 2/149
        'squadhelp.com'                           => ['status' => 'for_sale', 'label' => 'Squadhelp',     'verified' => true],
        'undeveloped.com'                         => ['status' => 'for_sale', 'label' => 'Undeveloped',   'verified' => false],
        'hugedomains.com'                         => ['status' => 'for_sale', 'label' => 'HugeDomains',   'verified' => false],
        'dropcatch.com'                           => ['status' => 'for_sale', 'label' => 'DropCatch',     'verified' => false],
        'brandbucket.com'                         => ['status' => 'for_sale', 'label' => 'BrandBucket',   'verified' => false],
        'domainnamesales.com'                     => ['status' => 'for_sale', 'label' => 'Media Options', 'verified' => false],
        'buydomains.com'                          => ['status' => 'for_sale', 'label' => 'BuyDomains',    'verified' => false],
        'mailbank.com'                            => ['status' => 'for_sale', 'label' => 'BuyDomains',    'verified' => false],
        'uniregistrymarket.link'                  => ['status' => 'for_sale', 'label' => 'Uniregistry',   'verified' => false],

        // ── Ad-monetisation parking ─────────────────────────────────────────
        'sedoparking.com'                         => ['status' => 'parked',   'label' => 'Sedo',          'verified' => true],  // 3/149
        'parkingcrew.net'                         => ['status' => 'parked',   'label' => 'ParkingCrew',   'verified' => true],  // 1/149
        'bodis.com'                               => ['status' => 'parked',   'label' => 'Bodis',         'verified' => false],
        'parklogic.com'                           => ['status' => 'parked',   'label' => 'ParkLogic',     'verified' => false],
        'fabulous.com'                            => ['status' => 'parked',   'label' => 'Fabulous',      'verified' => false],
        'smartname.com'                           => ['status' => 'parked',   'label' => 'SmartName',     'verified' => false],
        'trafficz.com'                            => ['status' => 'parked',   'label' => 'TrafficZ',      'verified' => false],
        'skenzo.com'                              => ['status' => 'parked',   'label' => 'Skenzo',        'verified' => false],
        'dsredirection.com'                       => ['status' => 'parked',   'label' => 'DomainSponsor', 'verified' => false],
        'voodoo.com'                              => ['status' => 'parked',   'label' => 'Voodoo',        'verified' => false],
    ],

    /**
     * Where a buyer would go, keyed by label. `{domain}` is substituted.
     * Only set for operators whose public listing URL is a stable pattern.
     */
    'listing_urls' => [
        'Afternic'     => 'https://www.afternic.com/domain/{domain}',
        'DAN.com'      => 'https://dan.com/buy-domain/{domain}',
        'HugeDomains'  => 'https://www.hugedomains.com/domain_profile.cfm?d={domain}',
        'Sedo'         => 'https://sedo.com/search/details/?domain={domain}',
        'Atom'         => 'https://www.atom.com/name/{domain}',
        'DomainMarket' => 'https://www.domainmarket.com/{domain}',
    ],

    /**
     * Registrars that exist mainly to hold aftermarket inventory.
     *
     * Kept deliberately narrow and used only as corroborating detail, never on
     * its own. The obvious idea — read the registrar from the RDAP response we
     * already fetch — does not work: Afternic, DAN and NameFind domains all
     * report `GoDaddy.com, LLC`, because the field names the registrar of
     * record, not the broker, and GoDaddy is the largest registrar in the
     * world. Treating that as a for-sale signal would flag millions of
     * operating businesses.
     */
    'broker_registrars' => [
        'turncommerce'  => 'HugeDomains / NameBright',
        'namebright'    => 'HugeDomains / NameBright',
        'dropcatch'     => 'DropCatch',
        'huge domains'  => 'HugeDomains',
    ],
];
