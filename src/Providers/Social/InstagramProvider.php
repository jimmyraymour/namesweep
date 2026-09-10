<?php

declare(strict_types=1);

namespace NameSweep\Providers\Social;

/**
 * Instagram handle classifier.
 *
 * Instagram commonly returns 200 even for non-existent handles (login wall or
 * an explicit "sorry, this page isn't available" error), so the bare 200-vs-404
 * distinction is unreliable:
 *   - explicit "this page isn't available"/"page not found" body → available
 *   - positive profile markers → registered
 *   - 200 with neither → uncertain (never guess)
 */
final class InstagramProvider implements SocialProviderInterface
{
    private const POSITIVE = [
        'edge_followed_by',
        '"profile_pic_url_hd"',
        '"bio":"',
        '"username":"',
    ];
    private const NOT_FOUND = [
        'this page isn\'t available',
        'this page is not available',
        'the link you followed may be broken',
        'page not found',
        'that page may have gone missing',
    ];

    public function platform(): string
    {
        return 'instagram';
    }

    public function classify(int $httpStatus, string $body): array
    {
        $lower = strtolower($body);

        if ($httpStatus === 404) {
            return ['status' => 'available', 'detail' => null];
        }
        if (in_array($httpStatus, [429, 403], true)) {
            return ['status' => 'uncertain', 'detail' => 'rate limited or login wall'];
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