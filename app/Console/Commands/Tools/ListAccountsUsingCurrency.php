<?php

/**
 * ListAccountsUsingCurrency.php
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Tools;

use FireflyIII\Models\Account;
use FireflyIII\Models\AccountMeta;
use FireflyIII\Models\TransactionCurrency;
use Illuminate\Console\Command;

use function Safe\json_encode;

class ListAccountsUsingCurrency extends Command
{
    protected $description = 'List accounts that have a given currency set (e.g. to find why a currency cannot be disabled).';

    protected $signature = 'firefly:list-accounts-using-currency {code : Currency code (e.g. EUR, AED)}';

    public function handle(): int
    {
        $code = strtoupper((string) $this->argument('code'));

        $currency = TransactionCurrency::where('code', $code)->first();
        if (!$currency instanceof TransactionCurrency) {
            $this->error(sprintf('Currency with code "%s" not found.', $code));

            return 1;
        }

        // Match how CurrencyRepository checks: data can be json_encode(int) or json_encode(string)
        $dataInt    = json_encode($currency->id);
        $dataString = json_encode((string) $currency->id);

        $metaRows = AccountMeta::query()
            ->where('name', 'currency_id')
            ->where(function ($q) use ($dataInt, $dataString): void {
                $q->where('data', $dataInt)->orWhere('data', $dataString);
            })
            ->get();

        if ($metaRows->isEmpty()) {
            $this->info(sprintf('No accounts have currency "%s" (%s) set.', $currency->name, $code));

            return 0;
        }

        $accountIds = $metaRows->pluck('account_id')->unique()->values()->all();
        $accounts   = Account::with('accountType')
            ->whereIn('id', $accountIds)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        $this->info(sprintf('Accounts using %s (%s):', $currency->name, $code));
        $this->newLine();

        /** @var Account $account */
        foreach ($accounts as $account) {
            $type = $account->accountType?->type ?? '?';
            $this->line(sprintf('  #%d  %s  (%s)', $account->id, $account->name, $type));
        }

        $this->newLine();
        $this->comment('To stop this currency from being "in use", edit each account above and set its currency to another (e.g. AED).');
        $this->comment('Then you can disable or delete the currency.');

        return 0;
    }
}
