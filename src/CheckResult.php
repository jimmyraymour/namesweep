<?php

declare(strict_types=1);

namespace NameSweep;

/**
 * Immutable value object — the uniform shape every module returns.
 */
final class CheckResult
{
    public function __construct(
        public readonly string $name,        // bare name
        public readonly string $tld,         // '' for non-domain modules
        public readonly string $status,      // available|registered|for_sale|parked|uncertain
        public readonly string $source,      // provider that produced this
        public readonly string $module,      // domain|marketplace|social|trademark
        public readonly string $platform,    // 'rdap' | 'whois' | 'twitter' | ...
        public readonly ?string $detail = null,
        public readonly ?float $price = null,
        public readonly ?string $url = null,
        public readonly string $checkedAt = '', // ISO 8601
        public readonly bool   $fromCache = false
    ) {
    }

    /**
     * JSON-ready array shape used by the API and CLI --json output.
     */
    public function toArray(): array
    {
        return [
            'name'       => $this->name,
            'tld'        => $this->tld,
            'status'     => $this->status,
            'source'     => $this->source,
            'module'     => $this->module,
            'platform'   => $this->platform,
            'detail'     => $this->detail,
            'price'      => $this->price,
            'url'        => $this->url,
            'checked_at' => $this->checkedAt !== '' ? $this->checkedAt : date('c'),
            'from_cache' => $this->fromCache,
        ];
    }
}
