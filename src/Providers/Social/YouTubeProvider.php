<?php

declare(strict_types=1);

namespace NameSweep\Providers\Social;

/**
 * YouTube handle classifier.
 *
 * YouTube returns 404 for non-existent @handles, and a 200 channel page carries
 * channel JSON:
 *   - 404 → available
 *   - explicit "this page isn't available" body → available
 *   - positive markers (channelMetadataRenderer / channel_id / subscriber) → registered
 *   - 200 with neither → uncertain
 */
final class YouTubeProvider implements SocialProviderInterface
{
    private const POSITIVE = [
        'channelMetadataRenderer',
        '"channel_id',
        'subscriberCountText',
        'channelid',
        '"externalChannelId"',
    ];
    private const NOT_FOUND = [
        'this page isn\'t available',
        'this page is not available',
        'page not found',
        'try searching for something else',
    ];

    public function platform(): string
    {
        return 'youtube';
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