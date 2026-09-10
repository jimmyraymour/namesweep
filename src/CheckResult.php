<?php

declare(strict_types=1);

namespace NameSweep;

/**
 * Immutable value object — the uniform shape every module returns.
 *
 * Two different kinds of money can appear on a result, and conflating them
 * would be the easiest way to mislead a reader:
 *
 *   $price            — what a SELLER is asking on the aftermarket. Only ever
 *                       set by the marketplace module, only for a listed
 *                       domain. Exposed as `sale_price` (with `price` kept as
 *                       a deprecated alias).
 *   $registrationPrice — what a REGISTRAR charges to register the suffix for a
 *                       year. A property of the suffix, so it is attached to
 *                       every domain row whatever its status: a taken .com
 *                       still shows what it would cost if it frees up.
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
        public readonly ?float $price = null,          // aftermarket asking price
        public readonly ?string $url = null,
        public readonly string $checkedAt = '',        // ISO 8601
        public readonly bool   $fromCache = false,
        public readonly ?float $registrationPrice = null,
        public readonly ?float $renewalPrice = null,
        public readonly ?float $transferPrice = null,
        public readonly ?string $currency = null,
        public readonly ?string $priceSource = null
    ) {
    }

    /**
     * A copy carrying registration pricing for this suffix.
     *
     * @param array{registration: ?float, renewal: ?float, transfer: ?float, currency: string, source: string}|null $pricing
     */
    public function withPricing(?array $pricing): self
    {
        if ($pricing === null) {
            return $this;
        }

        return new self(
            $this->name,
            $this->tld,
            $this->status,
            $this->source,
            $this->module,
            $this->platform,
            $this->detail,
            $this->price,
            $this->url,
            $this->checkedAt,
            $this->fromCache,
            $pricing['registration'] ?? null,
            $pricing['renewal'] ?? null,
            $pricing['transfer'] ?? null,
            $pricing['currency'] ?? null,
            $pricing['source'] ?? null
        );
    }

    /** True when we have any registration pricing for this row. */
    public function hasPricing(): bool
    {
        return $this->registrationPrice !== null || $this->renewalPrice !== null;
    }

    /**
     * The pricing block, or null when we know nothing about this suffix.
     *
     * A suffix with a configured source but no number still reports — with a
     * note saying why. Silence would read as "free" or "not checked"; the
     * report should say plainly that nobody publishes a price we can read.
     */
    private function pricingArray(): ?array
    {
        if (!$this->hasPricing() && $this->priceSource === null) {
            return null;
        }

        return [
            'registration' => $this->registrationPrice,
            'renewal'      => $this->renewalPrice,
            'transfer'     => $this->transferPrice,
            'currency'     => $this->currency ?? 'USD',
            'source'       => $this->priceSource,
            'note'         => $this->hasPricing()
                ? null
                : 'No published price available for this suffix — add one to config/pricing_overrides.php',
        ];
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
            // `price` is the aftermarket asking price. Kept for compatibility;
            // `sale_price` is the name to use.
            'price'      => $this->price,
            'sale_price' => $this->price,
            'pricing'    => $this->pricingArray(),
            'url'        => $this->url,
            'checked_at' => $this->checkedAt !== '' ? $this->checkedAt : date('c'),
            'from_cache' => $this->fromCache,
        ];
    }
}
