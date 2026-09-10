<?php

declare(strict_types=1);

namespace NameSweep\Web\Pages;

use NameSweep\Web\Layout;
use NameSweep\Web\Page;

/**
 * `/settings` — edit LLM prompts, enable/disable TLDs, view API keys.
 *
 * Overrides persist to storage/settings.json (gitignored); the config files
 * stay the versioned defaults. Saving uses POST → redirect (PRG) so a reload
 * never re-submits.
 */
final class SettingsPage extends Page
{
    public function render(): string
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->handlePost();
            $this->redirect($_SERVER['REQUEST_URI'] ?? '/settings');
        }

        $body  = $this->form();
        $body .= $this->apiKeys();
        return Layout::page('Settings', $body, ['report']);
    }

    private function handlePost(): void
    {
        $store   = $this->app['settings'];
        $current = $store->load();

        switch ($this->post('action')) {
            case 'prompts':
                $prompts = $current['prompts'] ?? [];
                foreach (['suggest_system', 'suggest_user', 'summary_system', 'summary_user'] as $key) {
                    $value = $this->post($key);
                    if ($value === '') {
                        unset($prompts[$key]); // empty = back to config default
                    } else {
                        $prompts[$key] = $value;
                    }
                }
                $current['prompts'] = $prompts;
                $store->save($current);
                break;

            case 'tlds':
                // Free-text extras first, so a suffix typed in this round can
                // also be ticked in the same save.
                $extras = $store->cleanSuffixes(preg_split('/[\s,]+/', $this->post('extra_tlds')) ?: []);
                if ($extras === []) {
                    unset($current['extra_tlds']);
                } else {
                    $current['extra_tlds'] = $extras;
                }

                $known   = array_merge(array_keys($this->app['config']['tlds']), $extras);
                $enabled = array_values(array_intersect($this->checked('tlds'), $known));
                $enabled = array_values(array_unique(array_merge($enabled, $extras)));

                if ($enabled === []) {
                    unset($current['tlds']);
                } else {
                    $current['tlds'] = $enabled;
                }
                $store->save($current);
                break;

            case 'reset':
                $store->save([]);
                break;
        }
    }

    private function redirect(string $url): void
    {
        header('Location: ' . $url . (str_contains($url, '?') ? '&' : '?') . 'saved=1');
        exit;
    }

    private function form(): string
    {
        $prompts = $this->app['config']['prompts'];
        $saved   = $this->app['settings']->load();

        $fields = '';
        foreach (['suggest_system', 'suggest_user', 'summary_system', 'summary_user'] as $key) {
            $hint = match ($key) {
                'suggest_system' => 'System prompt for candidate generation',
                'suggest_user'   => 'Placeholders: {description} {tlds} {rejected} {count}',
                'summary_system' => 'System prompt for report summaries',
                'summary_user'   => 'Placeholders: {name} {results_json}',
            };
            $fields .= '<div class="field"><label for="' . $key . '">' . Layout::e($key)
                . ' <span class="hint">' . Layout::e($hint) . '</span></label>'
                . '<textarea id="' . $key . '" name="' . $key . '" rows="3">' . Layout::e($prompts[$key] ?? '') . '</textarea></div>' . PHP_EOL;
        }

        $tldChecks = '';
        $enabledTlds = $saved['tlds'] ?? null;
        foreach (array_keys($this->app['config']['tlds']) as $tld) {
            $on = $enabledTlds === null || in_array($tld, $enabledTlds, true);
            $tldChecks .= $this->checkbox('tlds[]', $tld, $on, '.' . $tld);
        }
        $extraTlds = Layout::e(implode(', ', $saved['extra_tlds'] ?? []));

        return <<<HTML
<section class="panel">
    <h2>Settings</h2>
    <p class="hint">Overrides live in <span class="mono">storage/settings.json</span> (gitignored). Empty prompt fields use the config defaults.</p>
    <form method="post" action="/settings">
        <input type="hidden" name="action" value="prompts">
        <h3>LLM prompts</h3>
        {$fields}
        <button type="submit" class="btn">Save prompts</button>
    </form>
</section>
<section class="panel">
    <h3>Suffixes</h3>
    <p class="hint">Any delegated suffix works, whether or not it is listed here — these boxes only choose what the check form offers by default.</p>
    <form method="post" action="/settings">
        <input type="hidden" name="action" value="tlds">
        <div class="checks">{$tldChecks}</div>
        <div class="field">
            <label for="extra_tlds">Extra suffixes <span class="hint">comma or space separated, e.g. org, com.do, co.uk</span></label>
            <input type="text" id="extra_tlds" name="extra_tlds" value="{$extraTlds}" placeholder="org, ai, com.do">
        </div>
        <button type="submit" class="btn">Save suffixes</button>
    </form>
</section>
<section class="panel">
    <h3>Reset to defaults</h3>
    <form method="post" action="/settings" onsubmit="return confirm('Reset all settings overrides?');">
        <input type="hidden" name="action" value="reset">
        <button type="submit" class="btn btn-danger">Reset settings</button>
    </form>
</section>
HTML;
    }

    private function apiKeys(): string
    {
        if ($this->app['api_keys'] === null) {
            return '<section class="panel"><p class="muted">API keys unavailable (database down).</p></section>';
        }

        $rows = $this->app['api_keys']->all();
        if ($rows === []) {
            return '<section class="panel"><h3>API keys</h3><p class="muted">No API keys yet — create one with <span class="mono">php bin/namesweep key:create &lt;name&gt;</span>.</p></section>';
        }

        $table = '<table class="report-table"><thead><tr><th>ID</th><th>Name</th><th>Prefix</th><th>Created</th><th>Last used</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $status = $r['revoked_at'] !== null
                ? '<span class="badge status-parked">revoked</span>'
                : '<span class="badge status-available">active</span>';
            $table .= '<tr><td>' . (int) $r['id'] . '</td><td>' . Layout::e($r['name']) . '</td>'
                . '<td class="mono">' . Layout::e($r['key_prefix']) . '…</td>'
                . '<td>' . Layout::e($r['created_at']) . '</td>'
                . '<td>' . Layout::e((string) ($r['last_used_at'] ?? '—')) . '</td>'
                . '<td>' . $status . '</td></tr>' . PHP_EOL;
        }
        $table .= '</tbody></table>';

        return '<section class="panel"><h3>API keys</h3><p class="hint">Revoke keys with <span class="mono">php bin/namesweep key:revoke &lt;id&gt;</span>. Plaintext is never stored.</p>' . $table . '</section>';
    }
}