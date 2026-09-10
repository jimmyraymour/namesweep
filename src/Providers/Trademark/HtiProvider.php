<?php

declare(strict_types=1);

namespace NameSweep\Providers\Trademark;

use NameSweep\CheckResult;

/**
 * Haiti — manual-assist stub.
 *
 * No clean public registry API exists. Always `uncertain` with the exact
 * message from the spec; the UI/API owner resolves the row via
 * POST /api/v1/check/trademark/manual, which writes a `manual`-source row
 * that fresh-cache lookups then pick up.
 */
final class HtiProvider implements TrademarkProviderInterface
{
    public const MANUAL_REQUIRED = 'Haiti trademark check requires manual verification — no clean API available';

    public function key(): string
    {
        return 'hti';
    }

    public function check(string $name): CheckResult
    {
        return new CheckResult($name, '', 'uncertain', 'hti', 'trademark', 'hti', self::MANUAL_REQUIRED, null, null, date('c'), false);
    }
}