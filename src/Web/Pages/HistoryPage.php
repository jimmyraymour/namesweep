<?php

/* demo8: account/members/team-members.html (report table) */

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
            return Layout::page('History', $this->card('History', $this->errorHtml('Database unavailable.')));
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
            $table = '<p class="text-sm text-secondary-foreground">No checks cached yet — run one on the <a class="kt-link" href="' . $this->base() . '/">check page</a>.</p>';
        } else {
            $body = '';
            foreach ($rows as $r) {
                $badges = '';
                foreach (['available', 'registered', 'for_sale', 'parked', 'uncertain'] as $s) {
                    $n = (int) $r[$s];
                    if ($n > 0) {
                        $badges .= '<span class="kt-badge kt-badge-sm kt-badge-outline ' . $this->statusBadgeClass($s) . '">' . $s . ' ×' . $n . '</span> ';
                    }
                }
                $body .= '<tr><td class="font-mono min-w-[160px]">' . Layout::e($r['name']) . '</td>'
                    . '<td class="text-secondary-foreground">' . Layout::e($r['last_checked']) . '</td>'
                    . '<td><div class="flex flex-wrap gap-1.5">' . $badges . '</div></td>'
                    . '<td class="text-secondary-foreground">' . (int) $r['rows'] . '</td></tr>' . PHP_EOL;
            }
            $table = '<div class="kt-scrollable-x-auto"><table class="kt-table kt-table-border">'
                . '<thead><tr><th>Name</th><th>Last checked</th><th>Statuses</th><th>Rows</th></tr></thead>'
                . '<tbody>' . $body . '</tbody></table></div>';
        }

        $pages = (int) ceil($total / self::PAGE_SIZE);
        $nav = '';
        if ($pages > 1) {
            $b    = $this->base();
            $prev = $page > 1 ? '<a class="kt-btn kt-btn-sm kt-btn-outline" href="' . $b . '/history?page=' . ($page - 1) . '">← Newer</a>' : '<span class="kt-btn kt-btn-sm kt-btn-outline opacity-50 pointer-events-none">← Newer</span>';
            $next = $page < $pages ? '<a class="kt-btn kt-btn-sm kt-btn-outline" href="' . $b . '/history?page=' . ($page + 1) . '">Older →</a>' : '<span class="kt-btn kt-btn-sm kt-btn-outline opacity-50 pointer-events-none">Older →</span>';
            $nav = '<div class="flex items-center justify-center gap-2.5">' . $prev . '<span class="text-2sm text-secondary-foreground">page ' . $page . ' of ' . $pages . '</span>' . $next . '</div>';
        }

        return Layout::page('History', $this->card('History', '<p class="text-2sm text-secondary-foreground">' . $total . ' name(s) in cache, newest first.</p>' . $table . $nav));
    }
}
