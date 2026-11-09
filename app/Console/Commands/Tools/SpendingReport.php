<?php

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Tools;

use Carbon\Carbon;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SpendingReport extends Command
{
    protected $description = 'Generate a spending report grouped by merchant for budget analysis.';

    protected $signature = 'firefly:spending-report
                            {--months=3 : Number of months to look back}
                            {--from= : Start date (Y-m-d), overrides --months}
                            {--to= : End date (Y-m-d), defaults to today}';

    public function handle(): int
    {
        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))
            : Carbon::today('Asia/Dubai');

        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))
            : $to->copy()->subMonths((int) $this->option('months'))->startOfDay();

        $this->info(sprintf('Spending report: %s to %s', $from->format('Y-m-d'), $to->format('Y-m-d')));
        $this->newLine();

        $rows = DB::table('transactions as t')
            ->join('transaction_journals as tj', 'tj.id', '=', 't.transaction_journal_id')
            ->join('transaction_types as tt', 'tt.id', '=', 'tj.transaction_type_id')
            ->join('accounts as a', 'a.id', '=', 't.account_id')
            ->where('tt.type', TransactionTypeEnum::WITHDRAWAL->value)
            ->where('t.amount', '>', 0)   // destination side = expense account
            ->where('tj.date', '>=', $from)
            ->where('tj.date', '<=', $to)
            ->whereNull('t.deleted_at')
            ->whereNull('tj.deleted_at')
            ->select([
                'tj.date',
                'tj.description as journal_desc',
                't.description as txn_desc',
                't.amount',
                'a.name as merchant',
                'a.id as merchant_id',
            ])
            ->orderBy('tj.date')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('No withdrawal transactions found in this period.');
            return 0;
        }

        // --- Per-transaction listing ---
        $this->info('=== ALL WITHDRAWALS ===');
        $this->newLine();

        $totalSpend = 0.0;
        foreach ($rows as $r) {
            $date = Carbon::parse($r->date)->format('Y-m-d');
            $amt = (float) $r->amount;
            $desc = $r->journal_desc ?: $r->txn_desc ?: '(no description)';
            $totalSpend += $amt;

            $this->line(sprintf(
                '  %s | AED %10.2f | %s | %s',
                $date,
                $amt,
                str_pad($r->merchant ?? 'Unknown', 35),
                $desc
            ));
        }

        $this->newLine();
        $this->info(sprintf('Total withdrawals: AED %.2f  (%d transactions)', $totalSpend, $rows->count()));
        $this->newLine();

        // --- Grouped by merchant ---
        $this->info('=== SPENDING BY MERCHANT ===');
        $this->newLine();

        $grouped = $rows->groupBy('merchant');
        $merchantTotals = [];
        foreach ($grouped as $merchant => $txns) {
            $sum = $txns->sum(fn($r) => (float) $r->amount);
            $merchantTotals[$merchant] = [
                'total' => $sum,
                'count' => $txns->count(),
                'avg' => $sum / $txns->count(),
            ];
        }

        // Sort by total descending
        uasort($merchantTotals, fn($a, $b) => $b['total'] <=> $a['total']);

        foreach ($merchantTotals as $merchant => $data) {
            $this->line(sprintf(
                '  %-40s | AED %10.2f | %3d txns | avg AED %.2f',
                $merchant,
                $data['total'],
                $data['count'],
                $data['avg']
            ));
        }

        $this->newLine();

        // --- Monthly breakdown ---
        $this->info('=== MONTHLY TOTALS ===');
        $this->newLine();

        $byMonth = $rows->groupBy(fn($r) => Carbon::parse($r->date)->format('Y-m'));
        foreach ($byMonth as $month => $txns) {
            $sum = $txns->sum(fn($r) => (float) $r->amount);
            $this->line(sprintf('  %s: AED %.2f (%d transactions)', $month, $sum, $txns->count()));
        }

        $this->newLine();
        $months = max(1, $byMonth->count());
        $this->info(sprintf('Monthly average: AED %.2f', $totalSpend / $months));

        return 0;
    }
}
