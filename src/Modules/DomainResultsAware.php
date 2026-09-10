<?php

declare(strict_types=1);

namespace NameSweep\Modules;

use NameSweep\CheckResult;

/**
 * Implemented by modules whose work depends on what the DomainModule found.
 *
 * The Engine runs modules in order and hands the domain results to any later
 * module that implements this, so the module does not have to re-run the
 * domain check itself. Without it, MarketplaceModule called
 * `DomainModule::check()` internally and every domain lookup happened twice —
 * invisible while the cache absorbed it, and a doubled RDAP bill under
 * `--no-cache`.
 *
 * Implementations must still work when nothing is injected (for example when
 * the caller asked for `marketplace` without `domain`).
 */
interface DomainResultsAware
{
    /**
     * @param CheckResult[]|null $results the DomainModule's results for THIS
     *        request, or null to clear. The Engine clears before every check:
     *        these modules are long-lived singletons, so results left over
     *        from a previous request would otherwise be read as this one's.
     */
    public function setDomainResults(?array $results): void;
}
