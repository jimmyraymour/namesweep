<?php

declare(strict_types=1);

namespace NameSweep\Providers\Social;

/**
 * Contract for one social-platform handle checker.
 *
 * The SocialModule performs the HTTP GET (one shared HttpClient) and hands the
 * raw HTTP status + body to classify(). Each provider returns the definitive
 * CheckResult *status* for the handle:
 *
 *   - 'available'  only on a reliable "not found" signal (404 or an explicit
 *                  "this handle does not exist" body)
 *   - 'registered' only on a positive profile marker (never a bare 200 guess)
 *   - 'uncertain'  on rate limits or a 200 with no positive signal — the spec
 *                  is explicit: uncertain over guessing.
 *
 * @see docs/NameSweep-Spec.md §6.3
 */
interface SocialProviderInterface
{
    /** Cache-key platform, e.g. 'twitter' | 'instagram' | 'tiktok' | 'youtube'. */
    public function platform(): string;

    /**
     * @param int $httpStatus the raw HTTP status after following redirects
     * @return array{status:string, detail:?string}
     */
    public function classify(int $httpStatus, string $body): array;
}