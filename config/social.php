<?php

/**
 * Social platforms checked by the SocialModule.
 *
 * `url` uses {handle} as a placeholder for the bare name.
 * `method` is currently always http_get; kept as a field for future use.
 */

return [
    'twitter'   => ['url' => 'https://x.com/{handle}',                 'method' => 'http_get'],
    'instagram' => ['url' => 'https://www.instagram.com/{handle}/',    'method' => 'http_get'],
    'tiktok'    => ['url' => 'https://www.tiktok.com/@{handle}',       'method' => 'http_get'],
    'youtube'   => ['url' => 'https://www.youtube.com/@{handle}',      'method' => 'http_get'],
];
