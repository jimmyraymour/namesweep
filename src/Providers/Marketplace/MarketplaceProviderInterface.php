<?php

declare(strict_types=1);

namespace NameSweep\Providers\Marketplace;

use NameSweep\CheckResult;

/**
 * Contract for a single aftermarket/parked-data source used by the
 * MarketplaceModule.
 *
 * Conventions:
 *  - Return a CheckResult when this provider has a definitive answer
 *    (available / registered / for_sale / parked) for the domain.
 *  - Return null when the provider has no data about the domain (a "miss") —
 *    the module falls through to the next provider.
 *  - Throw a \RuntimeException on transport/parse errors — the module logs and
 *    falls through to the next provider, never crashing the check.
 */
interface MarketplaceProviderInterface
{
    /**
     * Cache-key platform, e.g. 'robotdomainsearch' | 'openprovider' | 'parkedpagedetector'.
     */
    public function key(): string;

    public function check(string $name, string $tld): ?CheckResult;
}