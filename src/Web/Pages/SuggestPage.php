<?php

/* demo8: account/home/settings-plain.html (form) + network/user-cards/mini-cards.html (candidate cards) */

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
                    return $this->errorHtml($error);
                }
                return $result;
            }
        }

        $body  = $this->form($error);
        $body .= $result !== null ? $result : '<div id="results">' . $this->card('Suggestions', '<p class="text-sm text-secondary-foreground">Suggestions appear here. Each round takes a minute or two.</p>') . '</div>';
        return Layout::page('Suggest', $body, ['suggest']);
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
        $tldChecks = '';
        $defaults = $this->app['config']['default_tlds'];
        foreach ($this->tlds() as $tld) {
            $tldChecks .= $this->checkbox('tlds[]', $tld, in_array($tld, $defaults, true), '.' . $tld);
        }
        $moduleChecks = $this->checkbox('modules[]', 'domain', true, 'Domain') . $this->checkbox('modules[]', 'social', true, 'Social');

        $descriptionField = $this->fieldRow('Project description', 'description',
            '<textarea id="description" name="description" rows="4" required class="kt-input min-h-[110px]"'
            . ' placeholder="A fintech app for Haitian small businesses, focused on mobile money.">' . $this->escPost('description') . '</textarea>');
        $countField = $this->fieldRow('How many names to keep', 'count',
            '<input type="number" id="count" name="count" min="1" max="50" value="10" class="kt-input max-w-28">');
        $tldsField = $this->fieldRow('TLDs', 'tlds', '<div class="flex flex-wrap gap-2.5">' . $tldChecks . '</div>');
        $modulesField = $this->fieldRow('Modules that must be available', 'modules', '<div class="flex flex-wrap gap-2.5">' . $moduleChecks . '</div>');
        $rejectsField = '<div class="flex flex-wrap gap-2.5">' . $this->checkbox('include_rejects', '1', true, 'Show rejected names') . '</div>';

        $submit = '<div class="flex items-center gap-2.5">'
            . '<button type="submit" class="kt-btn kt-btn-primary" id="suggest-btn">Suggest</button>'
            . '<span class="text-2sm text-secondary-foreground" id="suggest-wait" hidden>Working — this can take a few minutes...</span>'
            . '</div>';

        $fields = $descriptionField . $countField . $tldsField . $modulesField . $rejectsField . $submit;

        return '<form method="post" action="' . $this->base() . '/suggest" id="suggest-form">'
            . $this->card('Suggest names', $this->errorHtml($error) . $fields)
            . '</form>';
    }

    private function result(array $data): string
    {
        $candidates = $data['candidates'] ?? [];
        if ($candidates === []) {
            foreach (($data['kept'] ?? []) as $kept) {
                $candidates[] = ['name' => $kept['name'], 'checks' => $kept['checks'], 'cleared' => true, 'rejection_reason' => null];
            }
        }

        $cards = '';
        foreach ($candidates as $candidate) {
            $cleared = ($candidate['cleared'] ?? false) === true;
            $status  = $cleared ? 'cleared' : 'rejected';
            $reason  = $candidate['rejection_reason'] ?? 'did not clear all requested modules';
            $cards .= '<div class="kt-card">'
                . '<div class="kt-card-header"><h3 class="kt-card-title font-mono">' . Layout::e((string) $candidate['name']) . '</h3>'
                . '<span class="kt-badge kt-badge-sm kt-badge-outline ' . $this->statusBadgeClass($status) . '">' . $status . '</span></div>'
                . '<div class="kt-card-content grid gap-2.5">'
                . (!$cleared ? '<p class="text-2sm text-secondary-foreground">' . Layout::e((string) $reason) . '</p>' : '')
                . $this->checks($candidate['checks'] ?? [])
                . '</div></div>';
        }
        if ($cards === '') {
            $cards = '<p class="text-sm text-secondary-foreground">No candidates were returned.</p>';
        }

        $meta = '<p class="text-2sm text-secondary-foreground">Session <span class="font-mono">' . Layout::e((string) ($data['session_id'] ?? '')) . '</span> · ' . (int) ($data['iterations'] ?? 0) . ' iteration(s)</p>';

        return '<div id="results">' . $this->card('Suggestion report', $meta . '<div class="grid gap-5 lg:grid-cols-2 xl:grid-cols-3">' . $cards . '</div>') . '</div>';
    }

    private function checks(array $checks): string
    {
        $html = '<div class="flex flex-wrap gap-2">';
        foreach (($checks['results'] ?? []) as $rows) {
            foreach ($rows as $row) {
                $target = ($row['tld'] ?? '') !== '' ? '.' . $row['tld'] : '@' . ($row['platform'] ?? '');
                $status = (string) ($row['status'] ?? 'uncertain');
                $html .= '<span class="kt-badge kt-badge-sm kt-badge-outline ' . $this->statusBadgeClass($status) . '">'
                    . Layout::e($target) . ' ' . Layout::e($status)
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
        return ' · ' . $symbol . number_format((float) $registration, 2) . '/yr';
    }

    private function escPost(string $key): string
    {
        return Layout::e($this->post($key));
    }
}
