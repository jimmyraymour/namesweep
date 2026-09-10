<?php

declare(strict_types=1);

namespace NameSweep\Providers\Social;

/**
 * TikTok handle classifier.
 *
 * TikTok returns 404 for truly missing accounts but also serves a generic page
 * with an explicit "couldn't find this account" body in some cases, so:
 *   - 404 → available
 *   - explicit "couldn't find this account"/"page is not available" body → available
 *   - positive profile markers (user JSON) → registered
 *   - 200 with neither → uncertain
 */
final class TikTokProvider implements SocialProviderInterface
{
    private const POSITIVE = [
        '"uniqueId"',
        '"userInfo"',
        '"authorId"',
        'followerCount',
        '"nickname"',
    ];
    private const NOT_FOUND = [
        'couldn\'t find this account',
        'this page is not available',
        'could not find this account',
        'page is not available',
    ];

    public function platform(): string
    {
        return 'tiktok';
    }

    public function classify(int $httpStatus, string $body): array
    {
        $lower = strtolower($body);

        if ($httpStatus === 404) {
            return ['status' => 'available', 'detail' => null];
        }
        if (in_array($httpStatus, [429, 403], true)) {
            return ['status' => 'uncertain', 'detail' => 'rate limited or challenge page'];
        }

        if ($httpStatus === 200) {
            foreach (self::NOT_FOUND as $m) {
                if (str_contains($lower, $m)) {
                    return ['status' => 'available', 'detail' => "not-found signal: {$m}"];
                }
            }
            foreach (self::POSITIVE as $marker) {
                if (str_contains($lower, strtolower($marker))) {
                    return ['status' => 'registered', 'detail' => "marker: {$marker}"];
                }
            }
        }

        return ['status' => 'uncertain', 'detail' => 'HTTP ' . $httpStatus . ' with no positive profile marker'];
    }
}