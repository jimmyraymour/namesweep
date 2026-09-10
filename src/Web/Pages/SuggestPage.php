<?php

declare(strict_types=1);

namespace NameSweep\Web\Pages;

use NameSweep\Web\Layout;
use NameSweep\Web\Page;

/** `/suggest` — LLM suggestions rendered as a clearance report. */
final class SuggestPage extends Page
{
    public function render(): string
    {
        $result = null;
        $error  = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            [$result, $error] = $this->runSuggest();
            if ($this->isAjax()) {
                if ($error !== null) {
                    http_response_code(422);
                    return '<p class="error">' . Layout::e($error) . '</p>';
                }
                return $result;
            }
        }

        $body  = $this->form($error);
        $body .= $result !== null ? $result : '<section class="panel"><p class="muted">Suggestions appear here. Each round takes a minute or two.</p></section>';
        return Layout::page('Suggest', $body, ['suggest', 'report'], ['suggest']);
    }

    /** @return array{0:?string, 1:?string} */
    private function runSuggest(): array
    {
        $description = $this->post('description');
        if ($description === '') {
            $this->app['logger']->warning('Suggest: empty description posted', [], 'suggest');
            return [null, 'Describe the project before requesting names.'];
        }
        if ($this->app['suggest_engine'] === null) {
            $this->app['logger']->warning('Suggest: engine unavailable', ['db_error' => (string) ($this->app['db_error'] ?? 'unknown')], 'suggest');
            return [null, 'Suggest engine unavailable — database down (' . (string) ($this->app['db_error'] ?? 'unknown') . ').'];
        }

        $count = max(1, min(50, (int) ($_POST['count'] ?? 10)));
        try {
            $data = $this->app['suggest_engine']->suggest(
                $description,
                $count,
                $this->checked('tlds', $this->app['config']['default_tlds']),
                $this->checked('modules', ['domain', 'social']),
                isset($_POST['include_rejects'])
            );
        } catch (\Throwable $e) {
            $this->app['logger']->warning('Suggest failed', ['error' => $e->getMessage()], 'suggest');
            return [null, 'Suggest failed: ' . $e->getMessage()];
        }
        return [$this->result($data), null];
    }

    private function form(?string $error): string
    {
        $errorHtml = $error !== null ? '<p class="error">' . Layout::e($error) . '</p>' : '';
        $tldChecks = '';
        $defaults = $this->app['config']['default_tlds'];
        foreach ($this->tlds() as $tld) {
            $tldChecks .= $this->checkbox('tlds[]', $tld, in_array($tld, $defaults, true), '.' . $tld);
        }
        $moduleChecks = $this->checkbox('modules[]', 'domain', true, 'Domain') . $this->checkbox('modules[]', 'social', true, 'Social');

        return <<<HTML
<section class="panel">
    <h2>Suggest names</h2>
    {$errorHtml}
    <form method="post" action="{$this->base()}/suggest" id="suggest-form">
        <div class="field">
            <label for="description">Project description</label>
            <textarea id="description" name="description" rows="4" required placeholder="A fintech app for Haitian small businesses, focused on mobile money.">{$this->escPost('description')}</textarea>
        </div>
        <div class="field">
            <label for="count">How many names to keep</label>
            <input type="number" id="count" name="count" min="1" max="50" value="10">
        </div>
        <fieldset class="field"><legend>TLDs</legend><div class="checks">{$tldChecks}</div></fieldset>
        <fieldset class="field"><legend>Modules that must be available</legend><div class="checks">{$moduleChecks}</div></fieldset>
        <div class="checks">{$this->checkbox('include_rejects', '1', true, 'Show rejected names')}</div>
        <button type="submit" class="btn" id="suggest-btn">Suggest</button>
        <span class="hint" id="suggest-wait" hidden>Working - this can take a few minutes...</span>
    </form>
</section>
HTML;
    }

    private function result(array $data): string
    {
        $candidates = $data['candidates'] ?? [];
        if ($candidates === []) {
            foreach (($data['kept'] ?? []) as $kept) {
                $candidates[] = ['name' => $kept['name'], 'checks' => $kept['checks'], 'cleared' => true, 'rejection_reason' => null];
            }
        }

        $rows = '';
        foreach ($candidates as $candidate) {
            $cleared = ($candidate['cleared'] ?? false) === true;
            $status = $cleared ? 'cleared' : 'rejected';
            $reason = $candidate['rejection_reason'] ?? 'did not clear all requested modules';
            $rows .= '<article class="candidate ' . ($cleared ? 'candidate-cleared' : 'candidate-rejected') . '">'
                . '<div class="candidate-heading"><h3 class="mono">' . Layout::e((string) $candidate['name']) . '</h3>'
                . '<span class="badge ' . ($cleared ? 'status-available' : 'status-registered') . '">' . $status . '</span></div>'
                . (!$cleared ? '<p class="hint">' . Layout::e((string) $reason) . '</p>' : '')
                . $this->checks($candidate['checks'] ?? [])
                . '</article>';
        }
        if ($rows === '') {
            $rows = '<p class="muted">No candidates were returned.</p>';
        }

        return '<section class="panel results" id="results"><h2>Suggestion report</h2>'
            . '<p class="hint">Session <span class="mono">' . Layout::e((string) ($data['session_id'] ?? '')) . '</span> · ' . (int) ($data['iterations'] ?? 0) . ' iteration(s)</p>'
            . '<div class="candidate-list">' . $rows . '</div></section>';
    }

    private function checks(array $checks): string
    {
        $html = '<div class="tags">';
        foreach (($checks['results'] ?? []) as $rows) {
            foreach ($rows as $row) {
                $target = ($row['tld'] ?? '') !== '' ? '.' . $row['tld'] : '@' . ($row['platform'] ?? '');
                $html .= '<span class="tag tag-' . Layout::e((string) ($row['status'] ?? 'uncertain')) . '">'
                    . Layout::e($target) . ' ' . Layout::e((string) ($row['status'] ?? 'uncertain'))
                    . $this->priceHtml($row)
                    . '</span>';
            }
        }
        return $html . '</div>';
    }

    /** Registration price for a domain row, e.g. "$11.08/yr"; '' when unknown. */
    private function priceHtml(array $row): string
    {
        if (($row['module'] ?? '') !== 'domain') {
            return '';
        }
        $registration = $row['pricing']['registration'] ?? null;
        if (!is_numeric($registration)) {
            return '';
        }
        $currency = (string) ($row['pricing']['currency'] ?? 'USD');
        $symbol = $currency === 'USD' ? '$' : Layout::e($currency) . ' ';
        return ' <span class="tag-price">' . $symbol . number_format((float) $registration, 2) . '/yr</span>';
    }

    private function escPost(string $key): string
    {
        return Layout::e($this->post($key));
    }
}
