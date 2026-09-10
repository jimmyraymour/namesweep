<?php

declare(strict_types=1);

namespace NameSweep\Web\Pages;

use NameSweep\Web\Layout;
use NameSweep\Web\Page;

/**
 * `/history` — last checks from the cache, grouped by name, paginated.
 */
final class HistoryPage extends Page
{
    private const PAGE_SIZE = 25;

    public function render(): string
    {
        if ($this->app['db'] === null) {
            return Layout::page('History', '<p class="error">Database unavailable.</p>');
        }

        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $pdo   = $this->app['db']->pdo();
        $total = (int) $pdo->query('SELECT COUNT(DISTINCT name) FROM checks')->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT name,
                    MAX(checked_at)                                   AS last_checked,
                    SUM(status = \'available\')                        AS available,
                    SUM(status = \'registered\')                       AS registered,
                    SUM(status = \'for_sale\')                         AS for_sale,
                    SUM(status = \'parked\')                           AS parked,
                    SUM(status = \'uncertain\')                        AS uncertain,
                    COUNT(*)                                           AS `rows`
             FROM checks
             GROUP BY name
             ORDER BY last_checked DESC
             LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE)
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $table = '';
        if ($rows === []) {
            $table = '<p class="muted">No checks cached yet — run one on the <a href="' . $this->base() . '/">check page</a>.</p>';
        } else {
            $table = '<table class="report-table history-table"><thead><tr>'
                . '<th>Name</th><th>Last checked</th><th>Statuses</th><th>Rows</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $badges = '';
                foreach (['available', 'registered', 'for_sale', 'parked', 'uncertain'] as $s) {
                    $n = (int) $r[$s];
                    if ($n > 0) {
                        $badges .= '<span class="badge status-' . $s . '">' . $s . ' ×' . $n . '</span> ';
                    }
                }
                $table .= '<tr><td class="mono">' . Layout::e($r['name']) . '</td>'
                    . '<td>' . Layout::e($r['last_checked']) . '</td>'
                    . '<td>' . $badges . '</td>'
                    . '<td>' . (int) $r['rows'] . '</td></tr>' . PHP_EOL;
            }
            $table .= '</tbody></table>';
        }

        $pages = (int) ceil($total / self::PAGE_SIZE);
        $nav = '';
        if ($pages > 1) {
            $b    = $this->base();
            $prev = $page > 1 ? '<a class="btn btn-small" href="' . $b . '/history?page=' . ($page - 1) . '">← Newer</a>' : '<span class="muted">← Newer</span>';
            $next = $page < $pages ? '<a class="btn btn-small" href="' . $b . '/history?page=' . ($page + 1) . '">Older →</a>' : '<span class="muted">Older →</span>';
            $nav = '<div class="pager">' . $prev . ' <span class="hint">page ' . $page . ' of ' . $pages . '</span> ' . $next . '</div>';
        }

        return Layout::page('History', <<<HTML
<section class="panel">
    <h2>History</h2>
    <p class="hint">{$total} name(s) in cache, newest first.</p>
    {$table}
    {$nav}
</section>
HTML, ['report']);
    }
}