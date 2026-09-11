<?php

declare(strict_types=1);

namespace NameSweep\Web;

/**
 * demo8: index.html (shared chrome, via shared/inc/layout.php)
 *
 * Thin adapter over the root theme: builds NameSweep's nav items and current
 * user, then delegates the sidebar/header/toolbar/footer chrome to
 * raymour_layout_open()/raymour_layout_close(). Page bodies (src/Web/Pages/*)
 * build their own demo8 markup (kt-card/kt-input/kt-table/…) and pass it in
 * as $content; this class owns none of that markup itself.
 */
final class Layout
{
    /**
     * The URL path the app is mounted under, e.g. '/namesweep/public' when
     * deployed as a subdirectory, or '' at the domain root. Derived from
     * SCRIPT_NAME so it works on both the dev server and Apache subdirs.
     */
    public static function base(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        // dirname() itself emits a backslash on Windows PHP when the script
        // sits at the root (dirname('/index.php') === '\\') — normalize its
        // OUTPUT too, not just the input, or vhost mode builds links like
        // '\/assets/css/app.css' which browsers resolve to https://assets/.
        $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $base   = ($dir === '/' || $dir === '.') ? '' : $dir;
        return $base;
    }

    /**
     * Render $content inside the shared demo8 chrome.
     *
     * @param string[] $scripts page-specific JS module basenames (e.g. ['app'])
     */
    public static function page(string $title, string $content, array $scripts = []): string
    {
        require_once dirname(__DIR__, 3) . '/shared/inc/layout.php';

        $b       = self::base();
        $current = rtrim(parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/', '/');
        $current = $b !== '' && str_starts_with($current, $b) ? substr($current, strlen($b)) : $current;
        $current = $current === '' ? '/' : $current;
        $active  = static fn (string $route): bool => $route === '/' ? $current === '/' : str_starts_with($current, $route);

        $scriptsHtml = '';
        foreach ($scripts as $js) {
            $scriptsHtml .= '<script type="module" src="' . $b . '/assets/js/' . self::e($js) . '.js"></script>' . PHP_EOL;
        }

        ob_start();
        \raymour_layout_open([
            'html_title' => $title . ' · NameSweep',
            'title'      => $title,
            'app_slug'   => 'namesweep',
            'base_url'   => $b . '/',
            'nav_items'  => [
                ['icon' => 'ki-magnifier', 'label' => 'Check',    'href' => $b . '/',         'active' => $active('/')],
                ['icon' => 'ki-lightbulb', 'label' => 'Suggest',  'href' => $b . '/suggest',  'active' => $active('/suggest')],
                ['icon' => 'ki-time',      'label' => 'History',  'href' => $b . '/history',  'active' => $active('/history')],
                ['icon' => 'ki-setting-2', 'label' => 'Settings', 'href' => $b . '/settings', 'active' => $active('/settings')],
            ],
            'breadcrumb' => [['label' => 'NameSweep', 'href' => $b . '/'], ['label' => $title]],
            'user'       => \Bifrost::user(),
        ]);
        echo $content;
        \raymour_layout_close(['scripts' => $scriptsHtml]);
        return (string) ob_get_clean();
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
