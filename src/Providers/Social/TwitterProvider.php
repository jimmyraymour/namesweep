<?php

declare(strict_types=1);

namespace NameSweep\Providers\Social;

/**
 * X (Twitter) handle classifier.
 *
 * Positive profile markers (documented, verified 2026-08-17):
 *   - `"followersCount"`  (profile JSON embedded in the page)
 *   - `"profileImageUrlHttps"`, `"profileImageUrl"`
 *   - `<meta property="og:title">` / `og:description` containing the handle
 * X returns 404 for non-existent profiles; 200 without any positive marker is
 * treated as UNCERTAIN (login wall / bot wall) — never guessed available.
 */
final class TwitterProvider implements SocialProviderInterface
{
    private const POSITIVE = [
        'followersCount',
        'profileImageUrlHttps',
        'profileImageUrl',
        'canViewerEdit',
        'is Banned',
        '"isBanned"',
    ];

    public function platform(): string
    {
        return 'twitter';
    }

    public function classify(int $httpStatus, string $body): array
    {
        $lower = strtolower($body);

        if ($httpStatus === 404) {
            return ['status' => 'available', 'detail' => null];
        }

        if (in_array($httpStatus, [429, 403], true)) {
            return ['status' => 'uncertain', 'detail' => 'rate limited or bot wall'];
        }

        if ($httpStatus === 200) {
            foreach (self::POSITIVE as $marker) {
                if (str_contains($lower, strtolower($marker))) {
                    return ['status' => 'registered', 'detail' => "marker: {$marker}"];
                }
            }
        }

        return ['status' => 'uncertain', 'detail' => 'HTTP ' . $httpStatus . ' with no positive profile marker'];
    }
}