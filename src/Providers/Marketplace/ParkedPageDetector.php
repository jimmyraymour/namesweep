<?php

declare(strict_types=1);

namespace NameSweep\Providers\Marketplace;

use NameSweep\CheckResult;
use NameSweep\Util\Logger;

/**
 * ParkedPageDetector — the final aftermarket backstop.
 *
 * Fetches http://{name}.{tld}/ then https://{name}.{tld}/ (following redirects)
 * and looks for documented parked-page signatures in the returned HTML:
 *   - <meta name="description"> containing "this domain" / "buy this domain"
 *   - ad-network script names: sedoparking.com/js/, parkingcrew.net, bodis.com,
 *     googlesyndication (AdSense)
 *   - "<title>" containing "buy this domain" / parked boilerplate
 *
 * Returns:
 *   - `parked` when a signature is positively matched (definitive)
 *   - null otherwise — this is NOT parked per the available signatures. The
 *     caller treats no-parking-signature as "available" (domain module already
 *     said available; absence of a parked fingerprint is taken as not parked).
 * Brittle by design; it never guesses `parked` without a matched signature.
 */
final class ParkedPageDetector implements MarketplaceProviderInterface
{
    /**
     * Every needle must be a string that appears ONLY on a parked or for-sale
     * page. Anything that also occurs on ordinary websites belongs nowhere
     * near this list — a false `parked` is worse than no answer at all.
     *
     * Removed 2026-08-19: the `adsense` signature (`googlesyndication.com`,
     * `pagead2.googlesyndication`). AdSense runs on millions of legitimate
     * sites; it flagged nepal.com, a real travel guide, as parked. Parking
     * operators that serve AdSense are still caught by their own script hosts
     * below, and by the DNS tier before this one ever runs.
     *
     * Also tightened `this domain is` to the full sale/park phrases — the bare
     * prefix matches ordinary copy such as "this domain is operated by".
     *
     * @var list<array{name:string, needles:list<string>}>
     */
    private const SIGNATURES = [
        ['name' => 'for-sale-copy', 'needles' => ['buy this domain', 'this domain is for sale', 'this domain is available for', 'domain is for sale', 'for sale by owner']],
        ['name' => 'sedo-parking',  'needles' => ['sedoparking.com/js/']],
        ['name' => 'parkingcrew',   'needles' => ['parkingcrew.net']],
        ['name' => 'bodis',         'needles' => ['bodis.com']],
        ['name' => 'domain-parked', 'needles' => ['this domain is parked', 'domain parked by', 'is parked free with']],
        // Verified 2026-08-19: Afternic, DAN and NameFind all serve exactly
        // this 114-byte JS redirect from the bare domain.
        ['name' => 'js-lander',     'needles' => ['window.location.href="/lander"', "window.location.href='/lander'"]],
    ];

    public function __construct(
        private readonly \NameSweep\Util\HttpClient $http,
        private readonly Logger $logger
    ) {
    }

    public function key(): string
    {
        return 'parkedpagedetector';
    }

    public function check(string $name, string $tld): ?CheckResult
    {
        foreach (['http', 'https'] as $scheme) {
            $url = $scheme . '://' . $name . '.' . $tld . '/';
            try {
                $response = $this->http->get($url, ['Accept: text/html'], 3);
            } catch (\Throwable $e) {
                $this->logger->warning("Parked fetch failed {$url}", ['error' => $e->getMessage()], 'parked');
                continue; // try https
            }

            if ($response['status'] === 429) {
                $this->logger->warning("Parked fetch rate limited {$url}", [], 'parked');
                continue;
            }

            // Only inspect real HTML payloads.
            if ($response['status'] >= 200 && $response['status'] < 300 && $response['body'] !== '') {
                $matched = $this->match(strtolower($response['body']));
                if ($matched !== null) {
                    return new CheckResult($name, $tld, 'parked', 'parkedpagedetector', 'marketplace', 'parkedpagedetector', "Parked-page signature: {$matched} ({$url})", null, $url, date('c'), false);
                }
            }
        }

        return null; // not parked according to the known signatures
    }

    private function match(string $haystack): ?string
    {
        foreach (self::SIGNATURES as $sig) {
            foreach ($sig['needles'] as $needle) {
                if ($needle !== '' && str_contains($haystack, $needle)) {
                    return $sig['name'];
                }
            }
        }
        return null;
    }
}