<?php

declare(strict_types=1);

namespace NameSweep\Web;

/**
 * HTML shell shared by every page: head, sidebar, main, footer.
 * Shell mirrors metronic/dist/html/demo8 (sidebar + main card surface);
 * page bodies keep NameSweep's own classes, restyled by assets/css/base.css
 * to the same palette. No template engine — plain PHP string building.
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
     * @param string[] $styles page-specific css basenames (e.g. ['report'])
     * @param string[] $scripts page-specific js module basenames (e.g. ['app'])
     */
    public static function page(string $title, string $content, array $styles = [], array $scripts = []): string
    {
        $b = self::base();

        $styleLinks = '';
        foreach ($styles as $s) {
            $styleLinks .= '        <link rel="stylesheet" href="' . $b . '/assets/css/' . self::e($s) . '.css">' . PHP_EOL;
        }

        $scriptTags = '';
        foreach ($scripts as $js) {
            $scriptTags .= '    <script type="module" src="' . $b . '/assets/js/' . self::e($js) . '.js"></script>' . PHP_EOL;
        }

        $head = function_exists('raymour_theme') ? raymour_theme('light') : null;
        $themeHead = $head !== null ? $head['head']() : '';
        $modeJs    = $head !== null ? $head['mode_js']() : '';
        $themeJs   = $head !== null ? $head['scripts']() : '';

        $current = rtrim(parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/', '/');
        $current = $b !== '' && str_starts_with($current, $b) ? substr($current, strlen($b)) : $current;
        $current = $current === '' ? '/' : $current;

        $path = $b . '/';
        $nav = static function (string $href, string $icon, string $label, string $route) use ($current): string {
            $active = $route === '/' ? $current === '/' : str_starts_with($current, $route);
            $cls = 'kt-menu-link rounded-[9px] border border-transparent '
                 . 'kt-menu-item-active:border-border kt-menu-item-active:bg-background '
                 . 'kt-menu-link-hover:bg-background kt-menu-link-hover:border-border '
                 . 'w-[62px] h-[60px] flex flex-col justify-center items-center gap-1 p-2';
            $act = $active ? ' active' : '';
            return <<<HTML
        <div class="kt-menu-item{$act}">
         <a class="{$cls}" href="{$href}">
          <span class="kt-menu-icon kt-menu-item-here:text-primary kt-menu-item-active:text-primary kt-menu-link-hover:text-primary text-secondary-foreground">
           <i class="ki-filled {$icon} text-xl"></i>
          </span>
          <span class="kt-menu-title text-xs kt-menu-item-here:text-primary kt-menu-item-active:text-primary kt-menu-link-hover:text-primary text-secondary-foreground font-medium">{$label}</span>
         </a>
        </div>
HTML;
        };

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
{$themeHead}    <title>{$title} · NameSweep</title>
{$styleLinks}</head>
<body class="antialiased flex h-full text-base text-foreground bg-background [--header-height:60px] [--sidebar-width:90px] bg-muted">
{$modeJs}
<div class="flex grow">
  <header class="flex lg:hidden items-center fixed z-10 top-0 start-0 end-0 shrink-0 bg-muted h-(--header-height)" id="header">
    <div class="kt-container-fixed flex items-center justify-between flex-wrap gap-3">
      <a href="{$path}"><span class="text-base font-semibold text-mono">NameSweep</span></a>
      <button class="kt-btn kt-btn-icon kt-btn-ghost -me-1" data-kt-drawer-toggle="#sidebar">
        <i class="ki-filled ki-menu"></i>
      </button>
    </div>
  </header>
  <div class="flex flex-col lg:flex-row grow pt-(--header-height) lg:pt-0">
    <div class="fixed top-0 bottom-0 z-20 hidden lg:flex flex-col items-stretch shrink-0 bg-muted [--kt-drawer-enable:true] lg:[--kt-drawer-enable:false]" data-kt-drawer="true" data-kt-drawer-class="kt-drawer kt-drawer-start flex" id="sidebar">
      <div class="hidden lg:flex items-center justify-center shrink-0 pt-8 pb-3.5" id="sidebar_header">
        <a href="{$path}"><span class="text-lg font-semibold text-mono">NameSweep</span></a>
      </div>
      <div class="kt-scrollable-y-hover grow gap-2.5 shrink-0 flex items-center pt-5 lg:pt-0 ps-3 pe-3 lg:pe-0 flex-col" data-kt-scrollable="true" id="sidebar_menu_wrapper">
        <div class="kt-menu flex flex-col gap-2.5 grow" data-kt-menu="true" id="sidebar_menu">
{$nav($path, 'ki-magnifier', 'Check', '/')}
{$nav($b . '/suggest', 'ki-lightbulb', 'Suggest', '/suggest')}
{$nav($b . '/history', 'ki-time', 'History', '/history')}
{$nav($b . '/settings', 'ki-setting-2', 'Settings', '/settings')}
        </div>
      </div>
      <div class="flex flex-col gap-2.5 items-center shrink-0 pb-4" id="sidebar_footer">
      </div>
    </div>
    <div class="flex flex-col grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-5 m-5">
      <div class="flex flex-col grow kt-scrollable-y-auto lg:[--scrollbar-width:auto] pt-5" id="scrollable_content">
        <main class="grow" role="content">
          <div class="pb-5">
            <div class="kt-container-fixed flex items-center justify-between flex-wrap gap-3">
              <div class="flex items-center flex-wrap gap-1 lg:gap-5">
                <h1 class="font-medium text-base text-mono">{$title}</h1>
              </div>
            </div>
          </div>
          <div class="kt-container-fixed">
            <div class="grid gap-5 lg:gap-7.5">
{$content}
            </div>
          </div>
        </main>
        <footer class="text-center text-xs text-muted-foreground pb-5 pt-5">
          NameSweep — domain / social / marketplace / trademark clearance. Vanilla PHP, no framework.
        </footer>
      </div>
    </div>
  </div>
</div>
{$scriptTags}<script src="https://bifrost.raymour.local/shared/bar.js" data-current="namesweep" defer></script>
{$themeJs}</body>
</html>

HTML;
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
