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

    protected function checkbox(string $name, string $value, bool $checked, string $label, string $hint = ''): string
    {
        $hintHtml = $hint !== '' ? ' <span class="hint">' . Layout::e($hint) . '</span>' : '';
        return '<label class="check"><input type="checkbox" name="' . Layout::e($name) . '" value="' . Layout::e($value) . '"'
            . ($checked ? ' checked' : '') . '> ' . Layout::e($label) . $hintHtml . '</label>' . PHP_EOL;
    }
}