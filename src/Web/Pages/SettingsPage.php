<?php

/* demo8: account/home/settings-plain.html (forms) + account/api-keys.html (keys table) */

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
        return Layout::page('Settings', $body);
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
            $fields .= $this->fieldRow($key, $key, '<textarea id="' . $key . '" name="' . $key . '" rows="3" class="kt-input">' . Layout::e($prompts[$key] ?? '') . '</textarea>', $hint);
        }

        $tldChecks = '';
        $enabledTlds = $saved['tlds'] ?? null;
        foreach (array_keys($this->app['config']['tlds']) as $tld) {
            $on = $enabledTlds === null || in_array($tld, $enabledTlds, true);
            $tldChecks .= $this->checkbox('tlds[]', $tld, $on, '.' . $tld);
        }
        $extraTlds = Layout::e(implode(', ', $saved['extra_tlds'] ?? []));

        $promptsForm = '<form method="post" action="/settings">'
            . '<input type="hidden" name="action" value="prompts">'
            . $fields
            . '<div class="mt-2.5"><button type="submit" class="kt-btn kt-btn-primary">Save prompts</button></div>'
            . '</form>';
        $promptsCard = $this->card('LLM prompts', $promptsForm, '', 'llm_prompts');

        $extraField = $this->fieldRow('Extra suffixes', 'extra_tlds', '<input type="text" id="extra_tlds" name="extra_tlds" class="kt-input" value="' . $extraTlds . '" placeholder="org, ai, com.do">', 'Comma or space separated, e.g. org, com.do, co.uk');
        $tldsForm = '<form method="post" action="/settings">'
            . '<input type="hidden" name="action" value="tlds">'
            . '<div class="flex flex-wrap gap-2.5">' . $tldChecks . '</div>'
            . $extraField
            . '<div class="mt-2.5"><button type="submit" class="kt-btn kt-btn-primary">Save suffixes</button></div>'
            . '</form>';
        $tldsCard = $this->card('Suffixes',
            '<p class="text-2sm text-secondary-foreground">Any delegated suffix works, whether or not it is listed here — these boxes only choose what the check form offers by default.</p>' . $tldsForm);

        $resetForm = '<form method="post" action="/settings" onsubmit="return confirm(&quot;Reset all settings overrides?&quot;);">'
            . '<input type="hidden" name="action" value="reset">'
            . '<button type="submit" class="kt-btn kt-btn-destructive">Reset settings</button>'
            . '</form>';
        $resetCard = $this->card('Reset to defaults', $resetForm);

        return '<p class="text-2sm text-secondary-foreground">Overrides live in <span class="font-mono">storage/settings.json</span> (gitignored). Empty prompt fields use the config defaults.</p>'
            . $promptsCard . $tldsCard . $resetCard;
    }

    private function apiKeys(): string
    {
        if ($this->app['api_keys'] === null) {
            return $this->card('API keys', '<p class="text-sm text-secondary-foreground">API keys unavailable (database down).</p>');
        }

        $rows = $this->app['api_keys']->all();
        if ($rows === []) {
            return $this->card('API keys', '<p class="text-sm text-secondary-foreground">No API keys yet — create one with <span class="font-mono">php bin/namesweep key:create &lt;name&gt;</span>.</p>');
        }

        $body = '';
        foreach ($rows as $r) {
            $status = $r['revoked_at'] !== null ? 'revoked' : 'active';
            $body .= '<tr><td>' . (int) $r['id'] . '</td><td>' . Layout::e($r['name']) . '</td>'
                . '<td class="font-mono">' . Layout::e($r['key_prefix']) . '…</td>'
                . '<td class="text-secondary-foreground">' . Layout::e($r['created_at']) . '</td>'
                . '<td class="text-secondary-foreground">' . Layout::e((string) ($r['last_used_at'] ?? '—')) . '</td>'
                . '<td><span class="kt-badge kt-badge-sm kt-badge-outline ' . $this->statusBadgeClass($status) . '">' . $status . '</span></td></tr>' . PHP_EOL;
        }
        $table = '<div class="kt-scrollable-x-auto"><table class="kt-table kt-table-border">'
            . '<thead><tr><th>ID</th><th>Name</th><th>Prefix</th><th>Created</th><th>Last used</th><th>Status</th></tr></thead>'
            . '<tbody>' . $body . '</tbody></table></div>';

        return $this->card('API keys', '<p class="text-2sm text-secondary-foreground">Revoke keys with <span class="font-mono">php bin/namesweep key:revoke &lt;id&gt;</span>. Plaintext is never stored.</p>' . $table);
    }
}
