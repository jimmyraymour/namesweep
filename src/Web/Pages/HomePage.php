<?php

/* demo8: account/home/settings-plain.html (form) + account/members/team-members.html (report table) */

declare(strict_types=1);

namespace NameSweep\Web\Pages;

use NameSweep\CheckRequest;
use NameSweep\Providers\SuffixResolver;
use NameSweep\Util\ReportAnalyzer;
use NameSweep\Web\Layout;
use NameSweep\Web\Page;

/** `/` — batch check form and matrix report. */
final class HomePage extends Page
{
    public function render(): string
    {
        $report = '';
        $error  = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            [$report, $error] = $this->runCheck();
            if ($this->isAjax()) {
                if ($error !== null) {
                    http_response_code(422);
                    return $this->errorHtml($error);
                }
                return $report;
            }
        }

        $body  = $this->form($error);
        $body .= $report !== '' ? $report : $this->card('Run a check to see the report here.', '');
        return Layout::page('Check', $body, ['app']);
    }

    /** @return array{0:string, 1:?string} */
    private function runCheck(): array
    {
        $names = $this->postedNames();
        if ($names === []) {
            return ['', 'Enter one or more names, separated by commas or new lines.'];
        }
        if ($this->app['engine'] === null) {
            return ['', 'Engine unavailable — database down (' . (string) ($this->app['db_error'] ?? 'unknown') . ').'];
        }

        $reports = [];
        try {
            foreach ($names as $name) {
                $req = new CheckRequest(
                    $name,
                    $this->requestedTlds(),
                    $this->checked('modules'),
                    $this->checked('markets'),
                    isset($_POST['use_cache']),
                    null,
                    ($_POST['alternatives'] ?? '0') === '1',
                    $this->postedBudget(),
                    $this->postedCurrency()
                );
                $data = $this->app['engine']->checkAsArray($req);
                $data['verdict'] = ReportAnalyzer::verdict($data['results'], $req->budget, $req->currency);
                if (count($names) === 1 && $this->app['summary_writer'] !== null) {
                    $data['summary'] = $this->app['summary_writer']->forReport($data);
                }
                $reports[$name] = $data;
            }
        } catch (\Throwable $e) {
            return ['', $e->getMessage()];
        }

        return [$this->report($reports), null];
    }

    /** @return string[] */
    private function postedNames(): array
    {
        $raw = $this->post('names');
        if ($raw === '') {
            $raw = $this->post('name');
        }
        $names = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($names as $name) {
            $name = strtolower(trim($name));
            if (!preg_match('/^[a-z0-9-]{1,63}$/', $name)) {
                return [];
            }
            if (!in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** @return string[] */
    private function requestedTlds(): array
    {
        $typed = preg_split('/[\s,]+/', $this->post('extra_tlds')) ?: [];
        $extra = [];
        foreach ($typed as $candidate) {
            $suffix = SuffixResolver::normalize($candidate);
            if ($suffix !== '' && SuffixResolver::isValid($suffix)) {
                $extra[] = $suffix;
            }
        }
        $tlds = array_values(array_unique(array_merge($this->checked('tlds'), $extra)));
        return $tlds !== [] ? $tlds : $this->app['config']['default_tlds'];
    }

    private function form(?string $error): string
    {
        $tldChecks = '';
        $defaults  = $this->app['config']['default_tlds'];
        foreach ($this->tlds() as $tld) {
            $tldChecks .= $this->checkbox('tlds[]', $tld, in_array($tld, $defaults, true), '.' . $tld);
        }

        $moduleChecks = '';
        foreach ($this->engineModules() as $key => $label) {
            $moduleChecks .= $this->checkbox('modules[]', $key, true, $label);
        }
        $marketChecks = '';
        foreach (array_keys($this->app['config']['markets'] ?? []) as $market) {
            $marketChecks .= $this->checkbox('markets[]', $market, false, strtoupper($market), 'trademark only');
        }

        $currencyOptions = '';
        $postedCurrency = strtoupper($this->post('currency', 'USD'));
        foreach (['USD', 'EUR', 'GBP', 'DOP', 'HTG'] as $currency) {
            $currencyOptions .= '<option value="' . $currency . '"' . ($currency === $postedCurrency ? ' selected' : '') . '>' . $currency . '</option>' . PHP_EOL;
        }

        $namesField = $this->fieldRow('Names', 'names',
            '<textarea id="names" name="names" rows="3" required maxlength="2048" class="kt-input min-h-[90px]"'
            . ' placeholder="lumen&#10;kasav&#10;brightly">' . $this->escPost('names', $this->post('name')) . '</textarea>',
            'One per line or comma-separated.');

        $suffixesField = $this->fieldRow('Suffixes', 'extra_tlds',
            '<div class="flex flex-wrap gap-2.5">' . $tldChecks . '</div>'
            . '<input type="text" id="extra_tlds" name="extra_tlds" class="kt-input mt-2" placeholder="org, ai, com.do" value="' . $this->escPost('extra_tlds') . '">',
            'Any other suffix, comma or space separated, e.g. org, ai, com.do');

        $modulesField = $this->fieldRow('Modules', 'modules', '<div class="flex flex-wrap gap-2.5">' . $moduleChecks . '</div>');
        $marketsField = $this->fieldRow('Trademark markets', 'markets', '<div class="flex flex-wrap gap-2.5">' . $marketChecks . '</div>');

        $altField = $this->fieldRow('Alternatives & budget', 'budget',
            '<div class="flex flex-wrap gap-2.5">' . $this->checkbox('alternatives', '1', !isset($_POST['alternatives']) || $_POST['alternatives'] === '1', 'Also check alternative suffixes') . '</div>'
            . '<div class="flex flex-wrap gap-2.5 mt-2">'
            . '<input type="number" id="budget" name="budget" min="0" step="0.01" class="kt-input max-w-40" placeholder="e.g. 50" value="' . $this->escPost('budget') . '">'
            . '<select id="currency" name="currency" class="kt-select max-w-28">' . $currencyOptions . '</select>'
            . '</div>',
            'Budget rows it fits get a check; nothing is hidden.');

        $useCache = '<div class="flex flex-wrap gap-2.5">' . $this->checkbox('use_cache', '1', true, 'Use cache') . '</div>';

        $submit = '<div><button type="submit" class="kt-btn kt-btn-primary">Check</button></div>';

        $fields = $namesField . $suffixesField . $modulesField . $marketsField . $altField . $useCache . $submit;

        return '<form method="post" action="' . $this->base() . '/" id="check-form">'
            . $this->card('Check names', $this->errorHtml($error) . $fields)
            . '</form>';
    }

    /** @param array<string, array<string, mixed>> $reports */
    private function report(array $reports): string
    {
        $suffixes = [];
        foreach ($reports as $data) {
            foreach (($data['results']['domain'] ?? []) as $row) {
                $tld = (string) ($row['tld'] ?? '');
                if ($tld !== '' && !in_array($tld, $suffixes, true)) {
                    $suffixes[] = $tld;
                }
            }
        }

        $columns = '';
        foreach ($suffixes as $tld) {
            $columns .= '<th class="min-w-[110px]">.' . Layout::e($tld) . '</th>';
        }
        $body = '';
        $json = [];
        foreach ($reports as $name => $data) {
            $json[$name] = $data;
            $byTld = [];
            foreach (($data['results']['domain'] ?? []) as $row) {
                $byTld[(string) $row['tld']] = $row;
            }
            $cells = '';
            foreach ($suffixes as $tld) {
                $cells .= $this->matrixCell($byTld[$tld] ?? null);
            }
            $verdict = $data['verdict']['recommendation'] ?? '';
            $details = $this->details($data);
            $body .= '<tr><th scope="row" class="sticky-name font-mono text-sm text-mono min-w-[140px] bg-background">' . Layout::e($name) . '</th>' . $cells . '</tr>';
            $body .= '<tr><td colspan="' . (count($suffixes) + 1) . '" class="bg-muted/50">'
                . '<details><summary class="cursor-pointer text-sm font-medium text-mono">Full report for ' . Layout::e($name) . '</summary>'
                . '<p class="text-2sm text-secondary-foreground mt-2">' . Layout::e((string) $verdict) . '</p>' . $details . '</details></td></tr>';
        }

        $summary = '';
        if (count($reports) === 1) {
            $one = reset($reports);
            if (is_string($one['summary'] ?? null) && $one['summary'] !== '') {
                $summary = '<p class="text-sm text-foreground">' . Layout::e($one['summary']) . '</p>';
            }
        }
        $jsonText = htmlspecialchars((string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $actions = '<div class="flex items-center gap-2">'
            . '<button type="button" class="kt-btn kt-btn-sm kt-btn-outline" data-export="csv">Export CSV</button>'
            . '<button type="button" class="kt-btn kt-btn-sm kt-btn-outline" data-export="json">Export JSON</button>'
            . '</div>';

        $table = '<div class="kt-scrollable-x-auto"><table class="kt-table kt-table-border" data-report-json="' . $jsonText . '">'
            . '<thead><tr><th class="sticky-name min-w-[140px]">Name</th>' . $columns . '</tr></thead>'
            . '<tbody>' . $body . '</tbody></table></div>';

        return '<div id="report">' . $this->card('Matrix report', $summary . $table, $actions) . '</div>';
    }

    private function matrixCell(?array $row): string
    {
        if ($row === null) {
            return '<td><span class="text-2sm text-secondary-foreground">—</span></td>';
        }
        $status = (string) ($row['status'] ?? 'uncertain');
        $price = $row['sale_price'] ?? ($row['pricing']['registration'] ?? null);
        $money = is_numeric($price) ? '<div class="text-2sm text-secondary-foreground">' . Layout::e((string) ($row['pricing']['currency'] ?? 'USD')) . ' ' . number_format((float) $price, 2) . '</div>' : '';
        $budget = ($row['within_budget'] ?? null) === true ? ' <i class="ki-filled ki-check text-success" title="Within budget"></i>' : '';
        $alternativeClass = ($row['requested'] ?? true) === false ? 'opacity-60' : '';
        return '<td class="' . $alternativeClass . '">'
            . '<span class="kt-badge kt-badge-sm kt-badge-outline ' . $this->statusBadgeClass($status) . '">' . Layout::e($status) . '</span>' . $budget . $money . '</td>';
    }

    private function details(array $data): string
    {
        $html = '<div class="grid gap-3.5 mt-2">';
        foreach ($data['results'] as $module => $rows) {
            $html .= '<div><h4 class="text-sm font-medium text-mono">' . Layout::e(ucfirst($module)) . '</h4><ul class="mt-1.5 flex flex-col gap-1">';
            foreach ($rows as $row) {
                $target = ($row['tld'] ?? '') !== '' ? '.' . $row['tld'] : '@' . ($row['platform'] ?? '');
                $html .= '<li class="text-2sm text-secondary-foreground"><strong class="text-foreground">' . Layout::e($target) . '</strong>: ' . Layout::e((string) ($row['status'] ?? 'uncertain'))
                    . ' <span class="text-muted-foreground">' . Layout::e((string) ($row['source'] ?? '')) . '</span>'
                    . (($row['detail'] ?? null) !== null ? ' — ' . Layout::e((string) $row['detail']) : '') . '</li>';
            }
            $html .= '</ul></div>';
        }
        return $html . '</div>';
    }

    private function escPost(string $key, string $fallback = ''): string
    {
        $value = $this->post($key, '');
        return Layout::e($value !== '' ? $value : $fallback);
    }

    private function postedBudget(): ?float
    {
        $raw = $this->post('budget');
        return $raw !== '' && is_numeric($raw) ? (float) $raw : null;
    }

    private function postedCurrency(): string
    {
        $currency = strtoupper($this->post('currency', 'USD'));
        return in_array($currency, ['USD', 'EUR', 'GBP', 'DOP', 'HTG'], true) ? $currency : 'USD';
    }
}
