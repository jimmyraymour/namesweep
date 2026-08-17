<?php

declare(strict_types=1);

namespace NameSweep\Modules;

use NameSweep\CheckRequest;
use NameSweep\CheckResult;

/**
 * Contract every module implements.
 */
interface ModuleInterface
{
    /**
     * @return CheckResult[]
     */
    public function check(CheckRequest $req): array;

    /**
     * One-word identifier. Must match the `module` ENUM in the checks table.
     */
    public function name(): string;
}
