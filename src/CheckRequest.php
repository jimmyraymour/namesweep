<?php

declare(strict_types=1);

namespace NameSweep;

/**
 * Immutable value object describing a single check request.
 * Empty arrays mean "use the config default".
 */
final class CheckRequest
{
    public function __construct(
        public readonly string $name,            // bare name, no TLD, no spaces
        public readonly array  $tlds = [],       // e.g. ['com','net','io','do','ht']
        public readonly array  $modules = [],    // empty = run all enabled
        public readonly array  $markets = [],    // ['dr','ht','us']
        public readonly bool   $useCache = true,
        public readonly ?string $platform = null // single-platform social calls
    ) {
    }
}
