<?php

declare(strict_types=1);

namespace NameSweep\Web;

/**
 * Shared plumbing for the server-rendered pages.
 */
abstract class Page
{
    public function __construct(protected readonly array $app)
    {
    }

    abstract public function render(): string;

    /** True when the request is an AJAX (fragment) request from app.js. */
    protected function isAjax(): bool
    {
        return strtoupper((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'XMLHTTPREQUEST';
    }

    /** URL prefix the app is mounted under ('' at domain root). */
    protected function base(): string
    {
        return Layout::base();
    }

    /** @return string[] TLD keys from config/tlds.php (respecting settings overrides) */
    protected function tlds(): array
    {
        return array_keys($this->app['config']['tlds'] ?? []);
    }

    /** @return string[] enabled module keys */
    protected function modules(): array
    {
        return array_keys($this->engineModules());
    }

    /** @return array<string, string> module key => human label */
    protected function engineModules(): array
    {
        return ['domain' => 'Domain', 'marketplace' => 'Marketplace', 'social' => 'Social', 'trademark' => 'Trademark'];
    }

    /** @return string[] checked form values for a checkbox group */
    protected function checked(string $key, array $defaults = []): array
    {
        $values = $_POST[$key] ?? [];
        if (!is_array($values)) {
            $values = [$values];
        }
        $values = array_values(array_filter(array_map('strval', $values), static fn ($v) => $v !== ''));
        return $values !== [] ? $values : $defaults;
    }

    protected function post(string $key, string $default = ''): string
    {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
    }

    /* demo8: account/home/settings-plain.html (kt-checkbox rows) */
    protected function checkbox(string $name, string $value, bool $checked, string $label, string $hint = ''): string
    {
        $hintHtml = $hint !== '' ? ' <span class="text-2sm text-secondary-foreground">' . Layout::e($hint) . '</span>' : '';
        return '<label class="flex items-center gap-2 text-sm text-foreground">'
            . '<input class="kt-checkbox kt-checkbox-sm" type="checkbox" name="' . Layout::e($name) . '" value="' . Layout::e($value) . '"'
            . ($checked ? ' checked' : '') . '> ' . Layout::e($label) . $hintHtml . '</label>' . PHP_EOL;
    }

    /** A demo8 kt-card wrapping one titled section of a page. */
    protected function card(string $title, string $content, string $headerExtra = '', string $id = ''): string
    {
        $idAttr = $id !== '' ? ' id="' . Layout::e($id) . '"' : '';
        return '<div class="kt-card"' . $idAttr . '><div class="kt-card-header"><h3 class="kt-card-title">' . Layout::e($title) . '</h3>' . $headerExtra . '</div>'
            . '<div class="kt-card-content grid gap-5">' . $content . '</div></div>';
    }

    /** A demo8 label+control form row (settings-plain.html field pattern). */
    protected function fieldRow(string $label, string $for, string $controlHtml, string $hint = ''): string
    {
        $hintHtml = $hint !== '' ? '<div class="text-2sm text-secondary-foreground">' . Layout::e($hint) . '</div>' : '';
        return '<div class="flex flex-col lg:flex-row lg:items-baseline gap-2.5">'
            . '<label class="kt-form-label max-w-56" for="' . Layout::e($for) . '">' . Layout::e($label) . '</label>'
            . '<div class="grow flex flex-col gap-1.5">' . $controlHtml . $hintHtml . '</div></div>';
    }

    /** Inline error message (no kt-alert component ships in demo8; Tailwind utilities are fine per the theme rules). */
    protected function errorHtml(?string $error): string
    {
        if ($error === null || $error === '') {
            return '';
        }
        return '<div class="flex items-center gap-2 text-sm font-medium text-destructive">'
            . '<i class="ki-filled ki-information-2"></i>' . Layout::e($error) . '</div>';
    }

    /** Status string -> demo8 kt-badge color modifier (account/api-keys.html, account/members/team-members.html). */
    protected function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'available', 'cleared', 'active' => 'kt-badge-success',
            'registered', 'rejected' => 'kt-badge-destructive',
            'for_sale' => 'kt-badge-warning',
            'parked', 'revoked' => 'kt-badge-secondary',
            default => 'kt-badge-info', // uncertain
        };
    }
}