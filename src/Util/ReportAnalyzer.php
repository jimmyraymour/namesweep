<?php

declare(strict_types=1);

namespace NameSweep\Util;

/**
 * Deterministic, non-LLM interpretation of serialized check rows.
 */
final class ReportAnalyzer
{
    /**
     * @param array<string, array<int, array<string, mixed>>> $results
     * @return array<string, mixed>
     */
    public static function verdict(array $results, ?float $budget = null, string $currency = 'USD'): array
    {
        $domainRows = array_values(array_filter(
            $results['domain'] ?? [],
            static fn (array $row): bool => ($row['tld'] ?? '') !== ''
        ));

        $available = array_values(array_filter(
            $domainRows,
            static fn (array $row): bool => ($row['status'] ?? '') === 'available'
        ));
        $priced = array_values(array_filter($domainRows, static fn (array $row): bool => self::price($row) !== null));
        $within = array_values(array_filter(
            $priced,
            static fn (array $row): bool => ($row['within_budget'] ?? null) === true
        ));

        $sort = static function (array $left, array $right): int {
            $requested = ((bool) ($right['requested'] ?? true)) <=> ((bool) ($left['requested'] ?? true));
            if ($requested !== 0) {
                return $requested;
            }
            $budget = ((bool) ($right['within_budget'] ?? false)) <=> ((bool) ($left['within_budget'] ?? false));
            if ($budget !== 0) {
                return $budget;
            }
            return (self::price($left) ?? PHP_FLOAT_MAX) <=> (self::price($right) ?? PHP_FLOAT_MAX);
        };
        usort($available, $sort);
        usort($priced, static fn (array $a, array $b): int => (self::price($a) ?? PHP_FLOAT_MAX) <=> (self::price($b) ?? PHP_FLOAT_MAX));
        usort($within, static fn (array $a, array $b): int => (self::price($a) ?? PHP_FLOAT_MAX) <=> (self::price($b) ?? PHP_FLOAT_MAX));

        $blockedBy = [];
        foreach ($results as $module => $rows) {
            foreach ($rows as $row) {
                $status = (string) ($row['status'] ?? 'uncertain');
                if ($status !== 'available') {
                    $blockedBy[$module] = true;
                    break;
                }
            }
        }

        $best = $available[0] ?? null;
        $cheapest = $priced[0] ?? null;
        $cheapestWithin = $within[0] ?? null;

        $recommendation = 'No clear recommendation.';
        if ($best !== null) {
            $recommendation = 'Check ' . self::label($best) . ' first.';
        } elseif ($cheapestWithin !== null) {
            $recommendation = 'The cheapest priced option within budget is ' . self::label($cheapestWithin) . '.';
        } elseif ($cheapest !== null) {
            $recommendation = 'No domain is fully available; the cheapest priced option is ' . self::label($cheapest) . '.';
        } elseif ($blockedBy !== []) {
            $recommendation = 'No priced available domain was found; review the blocked modules.';
        }

        return [
            'best_available' => self::compact($best),
            'cheapest' => self::compact($cheapest),
            'cheapest_within_budget' => self::compact($cheapestWithin),
            'blocked_by' => array_keys($blockedBy),
            'recommendation' => $recommendation,
            'budget' => $budget,
            'currency' => strtoupper($currency),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function price(array $row): ?float
    {
        $sale = $row['sale_price'] ?? $row['price'] ?? null;
        if (is_numeric($sale)) {
            return (float) $sale;
        }
        $registration = $row['pricing']['registration'] ?? null;
        return is_numeric($registration) ? (float) $registration : null;
    }

    /** @param array<string, mixed> $row */
    private static function compact(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        return [
            'tld' => $row['tld'] ?? '',
            'status' => $row['status'] ?? 'uncertain',
            'price' => self::price($row),
            'currency' => $row['pricing']['currency'] ?? null,
            'requested' => $row['requested'] ?? true,
            'within_budget' => $row['within_budget'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function label(array $row): string
    {
        return '.' . (string) ($row['tld'] ?? 'unknown');
    }
}
