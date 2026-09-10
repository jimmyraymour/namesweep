<?php

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
                    return '<p class="error">' . Layout::e($error) . '</p>';
                }
                return $report;
            }
        }

        $body  = $this->form($error);
        $body .= $report !== '' ? $report : '<section class="panel"><p class="muted">Run a check to see the report here.</p></section>';
        return Layout::page('Check', $body, ['report'], ['app']);
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
        $errorHtml = $error !== null ? '<p class="error">' . Layout::e($error) . '</p>' : '';
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

        return <<<HTML
<section class="panel">
    <h2>Check names</h2>
    {$errorHtml}
    <form method="post" action="{$this->base()}/" id="check-form">
        <div class="field">
            <label for="names">Names</label>
            <textarea id="names" name="names" rows="3" required maxlength="2048"
                      placeholder="lumen\nkasav\nbrightly">{$this->escPost('names', $this->post('name'))}</textarea>
            <span class="hint">One per line or comma-separated.</span>
        </div>
        <fieldset class="field">
            <legend>Suffixes</legend>
            <div class="checks">{$tldChecks}</div>
            <label for="extra_tlds" class="sublabel">Any other suffix
                <span class="hint">comma or space separated, e.g. org, ai, com.do</span></label>
            <input type="text" id="extra_tlds" name="extra_tlds" placeholder="org, ai, com.do"
                   value="{$this->escPost('extra_tlds')}">
        </fieldset>
        <fieldset class="field">
            <legend>Modules</legend>
            <div class="checks">{$moduleChecks}</div>
        </fieldset>
        <fieldset class="field">
            <legend>Trademark markets</legend>
            <div class="checks">{$marketChecks}</div>
        </fieldset>
        <fieldset class="field">
            <legend>Alternatives &amp; budget</legend>
            <div class="checks">
                {$this->checkbox('alternatives', '1', !isset($_POST['alternatives']) || $_POST['alternatives'] === '1', 'Also check alternative suffixes')}
            </div>
            <label for="budget" class="sublabel">Budget <span class="hint">rows it fits get a check; nothing is hidden</span></label>
            <input type="number" id="budget" name="budget" min="0" step="0.01" placeholder="e.g. 50" value="{$this->escPost('budget')}">
            <label for="currency" class="sublabel">Currency</label>
            <select id="currency" name="currency">{$currencyOptions}</select>
        </fieldset>
        <div class="checks">{$this->checkbox('use_cache', '1', true, 'Use cache')}</div>
        <button type="submit" class="btn">Check</button>
    </form>
</section>
HTML;
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
            $columns .= '<th scope="col">.' . Layout::e($tld) . '</th>';
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
            $body .= '<tr><th scope="row" class="sticky-name mono">' . Layout::e($name) . '</th>' . $cells . '</tr>';
            $body .= '<tr class="detail-row"><td colspan="' . (count($suffixes) + 1) . '"><details><summary>Full report for ' . Layout::e($name) . '</summary>'
                . '<p class="hint">' . Layout::e((string) $verdict) . '</p>' . $details . '</details></td></tr>';
        }

        $summary = '';
        if (count($reports) === 1) {
            $one = reset($reports);
            if (is_string($one['summary'] ?? null) && $one['summary'] !== '') {
                $summary = '<p class="summary">' . Layout::e($one['summary']) . '</p>';
            }
        }
        $jsonText = htmlspecialchars((string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<section class="panel report" id="report">'
            . '<div class="report-heading"><div><h2>Matrix report</h2><p class="hint">Rows are names; columns are suffixes. Expand a row for module details.</p></div>'
            . '<div class="report-actions"><button type="button" class="btn btn-small" data-export="csv">Export CSV</button><button type="button" class="btn btn-small" data-export="json">Export JSON</button></div></div>'
            . $summary
            . '<div class="matrix-wrap"><table class="matrix-table" data-report-json="' . $jsonText . '"><thead><tr><th scope="col" class="sticky-name">Name</th>' . $columns . '</tr></thead><tbody>' . $body . '</tbody></table></div>'
            . '</section>';
    }

    private function matrixCell(?array $row): string
    {
        if ($row === null) {
            return '<td class="matrix-cell"><span class="hint">—</span></td>';
        }
        $status = (string) ($row['status'] ?? 'uncertain');
        $price = $row['sale_price'] ?? ($row['pricing']['registration'] ?? null);
        $money = is_numeric($price) ? '<span class="cell-price">' . Layout::e((string) ($row['pricing']['currency'] ?? 'USD')) . ' ' . number_format((float) $price, 2) . '</span>' : '';
        $budget = ($row['within_budget'] ?? null) === true ? '<span class="budget-mark" title="Within budget">&#10003;</span>' : '';
        $alternative = ($row['requested'] ?? true) === false ? ' alternative' : '';
        return '<td class="matrix-cell status-' . Layout::e($status) . $alternative . '"><span class="matrix-status">' . Layout::e($status) . '</span>' . $budget . $money . '</td>';
    }

    private function details(array $data): string
    {
        $html = '';
        foreach ($data['results'] as $module => $rows) {
            $html .= '<section class="detail-module"><h4>' . Layout::e(ucfirst($module)) . '</h4><ul>';
            foreach ($rows as $row) {
                $target = ($row['tld'] ?? '') !== '' ? '.' . $row['tld'] : '@' . ($row['platform'] ?? '');
                $html .= '<li><strong>' . Layout::e($target) . '</strong>: ' . Layout::e((string) ($row['status'] ?? 'uncertain'))
                    . ' <span class="hint">' . Layout::e((string) ($row['source'] ?? '')) . '</span>'
                    . (($row['detail'] ?? null) !== null ? ' <span class="detail">' . Layout::e((string) $row['detail']) . '</span>' : '') . '</li>';
            }
            $html .= '</ul></section>';
        }
        return $html;
    }

    private function priceHtml(array $r): string
    {
        $currency = $r['pricing']['currency'] ?? 'USD';
        $symbol = $currency === 'USD' ? '$' : Layout::e($currency) . ' ';
        $sale = $r['sale_price'] ?? $r['price'] ?? null;
        if ($sale !== null) {
            return ' <span class="price price-ask" title="Seller\'s asking price">asking ' . $symbol . number_format((float) $sale, 2) . '</span>';
        }
        $reg = $r['pricing']['registration'] ?? null;
        if ($reg !== null) {
            return ' <span class="price price-reg">' . $symbol . number_format((float) $reg, 2) . '/yr</span>';
        }
        return ($r['pricing']['note'] ?? null) !== null ? ' <span class="hint">no price</span>' : '';
    }

    private function moduleLabel(string $key): string
    {
        return $this->engineModules()[$key] ?? ucfirst($key);
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
