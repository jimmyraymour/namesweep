<?php

declare(strict_types=1);

namespace NameSweep\Providers\Trademark;

use NameSweep\CheckResult;

/**
 * A single trademark registry lookup for one jurisdiction/market.
 *
 * Providers are keyed (onapi|hti|uspto) and return one definitive CheckResult
 * per market. The market code itself is NOT the platform: the cache is keyed
 * on the provider key, so a manual verify for market 'dr' lands under 'onapi'
 * and a later check reads it back as fresh.
 */
interface TrademarkProviderInterface
{
    public function key(): string;

    /** @return CheckResult available|registered|uncertain, tld always '' */
    public function check(string $name): CheckResult;
}