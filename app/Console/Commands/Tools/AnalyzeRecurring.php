<?php

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Tools;

use Carbon\Carbon;
use Illuminate\Console\Command;

class AnalyzeRecurring extends Command
{
    protected $description = 'Analyze transactions JSON to detect recurring payments and subscriptions.';

    protected $signature = 'firefly:analyze-recurring {file : Path to transactions JSON}';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (!file_exists($file) || !is_readable($file)) {
            $this->error(sprintf('File not found: %s', $file));

            return 1;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data) || !array_key_exists('transactions', $data)) {
            $this->error('Invalid JSON.');

            return 1;
        }

        $txns = $data['transactions'];

        // Only settled/success DR transactions (actual outgoing payments)
        $filtered = array_filter($txns, function (array $t) {
            $status = strtoupper($t['status'] ?? '');
            if (in_array($status, ['FAILED', 'CANCELLED', 'REVERSED', 'REFUNDED'], true)) {
                return false;
            }
            if (($t['creditDebitIndicator'] ?? '') !== 'DR') {
                return false;
            }
            $amt = (float) ($t['accountAmount']['amount'] ?? $t['amount'] ?? 0);

            return $amt > 0;
        });

        // Group by cleaned merchant name
        $groups = [];
        foreach ($filtered as $t) {
            $key = $this->merchantKey($t);
            $dateMs = $t['date'] ?? $t['transactionDate'] ?? 0;
            $date = Carbon::createFromTimestampMs($dateMs)->setTimezone('Asia/Dubai');
            $amt = (float) ($t['accountAmount']['amount'] ?? $t['amount'] ?? 0);

            $groups[$key][] = [
                'date'   => $date,
                'amount' => $amt,
                'type'   => $t['type'] ?? '',
            ];
        }

        // Sort each group by date
        foreach ($groups as &$entries) {
            usort($entries, fn ($a, $b) => $a['date']->timestamp <=> $b['date']->timestamp);
        }
        unset($entries);

        // Sub-group by amount when a merchant has clearly distinct price points
        $refined = [];
        foreach ($groups as $merchant => $entries) {
            $subGroups = $this->splitByAmount($entries);
            if (count($subGroups) === 1) {
                $refined[$merchant] = $entries;
            } else {
                foreach ($subGroups as $i => $sub) {
                    $avgAmt = round(array_sum(array_column($sub, 'amount')) / count($sub), 2);
                    $refined[sprintf('%s (AED %.2f)', $merchant, $avgAmt)] = $sub;
                }
            }
        }
        $groups = $refined;

        // Detect recurring: 2+ occurrences with a consistent interval
        $recurring = [];
        foreach ($groups as $merchant => $entries) {
            if (count($entries) < 2) {
                continue;
            }

            $intervals = [];
            for ($i = 1; $i < count($entries); $i++) {
                $intervals[] = $entries[$i - 1]['date']->diffInDays($entries[$i]['date']);
            }

            $avgInterval = array_sum($intervals) / count($intervals);
            $frequency = $this->classifyFrequency($avgInterval, $intervals);

            if (null === $frequency) {
                continue;
            }

            $amounts = array_column($entries, 'amount');
            $isFixedAmount = (max($amounts) - min($amounts)) < 1.00;

            $recurring[] = [
                'merchant'     => $merchant,
                'frequency'    => $frequency,
                'avg_interval' => round($avgInterval, 1),
                'occurrences'  => count($entries),
                'avg_amount'   => round(array_sum($amounts) / count($amounts), 2),
                'min_amount'   => min($amounts),
                'max_amount'   => max($amounts),
                'fixed_amount' => $isFixedAmount,
                'first_date'   => $entries[0]['date']->format('Y-m-d'),
                'last_date'    => end($entries)['date']->format('Y-m-d'),
                'next_expected' => $this->predictNext(end($entries)['date'], $avgInterval),
                'dates'        => array_map(fn ($e) => $e['date']->format('Y-m-d'), $entries),
                'amounts'      => $amounts,
            ];
        }

        // Sort: subscriptions (fixed amount + monthly) first, then by frequency
        usort($recurring, function ($a, $b) {
            $order = ['weekly' => 1, 'bi-weekly' => 2, 'monthly' => 3, 'bi-monthly' => 4, 'quarterly' => 5, 'irregular' => 6];

            return ($order[$a['frequency']] ?? 99) <=> ($order[$b['frequency']] ?? 99);
        });

        // Output
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════════════');
        $this->info('  RECURRING TRANSACTIONS ANALYSIS');
        $this->info('═══════════════════════════════════════════════════════════════════════');
        $this->newLine();

        $totalMonthly = 0.0;

        foreach ($recurring as $r) {
            $amountStr = $r['fixed_amount']
                ? sprintf('AED %.2f', $r['avg_amount'])
                : sprintf('AED %.2f – %.2f (avg %.2f)', $r['min_amount'], $r['max_amount'], $r['avg_amount']);

            $this->line(sprintf(
                '  <info>%s</info>',
                str_pad($r['merchant'], 45)
            ));
            $this->line(sprintf(
                '    Frequency:    <comment>%s</comment> (every ~%s days)',
                strtoupper($r['frequency']),
                $r['avg_interval']
            ));
            $this->line(sprintf('    Amount:       %s', $amountStr));
            $this->line(sprintf('    Occurrences:  %d (%s → %s)', $r['occurrences'], $r['first_date'], $r['last_date']));
            $this->line(sprintf('    Next expected: %s', $r['next_expected']));
            $this->line(sprintf('    Dates:        %s', implode(', ', $r['dates'])));

            if (!$r['fixed_amount']) {
                $this->line(sprintf('    Amounts:      %s', implode(', ', array_map(fn ($a) => number_format($a, 2), $r['amounts']))));
            }

            $this->newLine();

            if ('monthly' === $r['frequency']) {
                $totalMonthly += $r['avg_amount'];
            }
        }

        $this->info('═══════════════════════════════════════════════════════════════════════');
        $this->info(sprintf('  Total recurring items found: %d', count($recurring)));
        $this->info(sprintf('  Estimated monthly recurring spend: AED %.2f', $totalMonthly));
        $this->info('═══════════════════════════════════════════════════════════════════════');

        return 0;
    }

    private function merchantKey(array $t): string
    {
        // Prefer the English title for accurate grouping
        foreach ($t['purpose']['extendedNarrations'] ?? [] as $n) {
            if ('en' === ($n['languange'] ?? '')) {
                $title = trim($n['title'] ?? '');
                if ('' !== $title) {
                    return $this->normalizeMerchant($title);
                }
            }
        }

        $terminal = $t['terminal']['name'] ?? null;
        if ($terminal) {
            return $this->normalizeMerchant($terminal);
        }

        $mlKey = $t['mlEnriched']['merchantKey'] ?? null;
        if ($mlKey && '' !== $mlKey) {
            return $this->titleCase($mlKey);
        }

        return 'Unknown';
    }

    private function normalizeMerchant(string $name): string
    {
        $name = trim($name);
        // Strip trailing location/codes
        $name = preg_replace('/\s*\([^)]+\)\s*$/', '', $name) ?? $name;
        // Remove unique codes like "P3DC4D2299" from Spotify
        $name = preg_replace('/\s+P[0-9A-F]{8,}$/i', '', $name) ?? $name;
        // Remove trailing asterisk codes like "*71528128"
        $name = preg_replace('/\s*\*\d{6,}$/', '', $name) ?? $name;
        // Remove "GOOGLE*" prefix for cleaner grouping
        if (preg_match('/^GOOGLE\*(.+)/i', $name, $m)) {
            $name = $m[1];
        }
        // Remove "PAYPAL *" prefix
        if (preg_match('/^PAYPAL\s*\*\s*(.+)/i', $name, $m)) {
            $name = $m[1];
        }
        // Collapse whitespace
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name);
    }

    private function titleCase(string $key): string
    {
        return str_replace('_', ' ', ucwords(str_replace(['_', '-'], [' ', ' '], $key)));
    }

    private function classifyFrequency(float $avg, array $intervals): ?string
    {
        if (count($intervals) < 1) {
            return null;
        }

        $buckets = [
            'weekly'     => [5, 10],
            'bi-weekly'  => [12, 18],
            'monthly'    => [25, 35],
            'bi-monthly' => [55, 70],
            'quarterly'  => [85, 100],
        ];

        // Primary: check if average fits a bucket
        foreach ($buckets as $label => [$lo, $hi]) {
            if ($avg >= $lo && $avg <= $hi) {
                return $label;
            }
        }

        // Fallback: check if a majority of intervals fit a bucket
        // (handles cases like Google One where early double-charges pull the average down)
        foreach ($buckets as $label => [$lo, $hi]) {
            $inBucket = 0;
            foreach ($intervals as $gap) {
                if ($gap >= $lo - 3 && $gap <= $hi + 3) {
                    $inBucket++;
                }
            }
            if ($inBucket / count($intervals) >= 0.4 && $inBucket >= 2) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Split entries into sub-groups when amounts cluster around distinct values.
     * E.g. Starzplay has 19.99 and 44.99 — these are separate subscriptions.
     */
    private function splitByAmount(array $entries): array
    {
        if (count($entries) < 3) {
            return [$entries];
        }

        $amounts = array_column($entries, 'amount');
        $min = min($amounts);
        $max = max($amounts);

        // If amounts are within 20% of each other, it's one group
        if ($max - $min < max($min * 0.25, 2.0)) {
            return [$entries];
        }

        // Cluster amounts using a simple gap-based approach
        $sorted = $amounts;
        sort($sorted);
        $clusters = [[$sorted[0]]];
        for ($i = 1; $i < count($sorted); $i++) {
            $lastCluster = &$clusters[count($clusters) - 1];
            $clusterAvg = array_sum($lastCluster) / count($lastCluster);
            if (abs($sorted[$i] - $clusterAvg) < max($clusterAvg * 0.15, 2.0)) {
                $lastCluster[] = $sorted[$i];
            } else {
                $clusters[] = [$sorted[$i]];
            }
            unset($lastCluster);
        }

        if (count($clusters) < 2) {
            return [$entries];
        }

        // Only split if each cluster has 2+ entries
        $clusterCenters = array_map(fn ($c) => array_sum($c) / count($c), $clusters);
        $subGroups = array_fill(0, count($clusters), []);
        foreach ($entries as $entry) {
            $bestCluster = 0;
            $bestDist = PHP_FLOAT_MAX;
            foreach ($clusterCenters as $ci => $center) {
                $dist = abs($entry['amount'] - $center);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $bestCluster = $ci;
                }
            }
            $subGroups[$bestCluster][] = $entry;
        }

        // Only keep sub-groups with 2+ entries
        $subGroups = array_values(array_filter($subGroups, fn ($g) => count($g) >= 2));
        if (count($subGroups) < 2) {
            return [$entries];
        }

        return $subGroups;
    }

    private function predictNext(Carbon $lastDate, float $avgInterval): string
    {
        return $lastDate->copy()->addDays((int) round($avgInterval))->format('Y-m-d');
    }
}
