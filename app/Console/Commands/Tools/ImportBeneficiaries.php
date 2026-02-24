<?php

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Tools;

use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

use function Safe\json_decode;

class ImportBeneficiaries extends Command
{
    protected $description = 'Import beneficiaries from Emirates NBD JSON export as Firefly III expense accounts.';

    protected $signature = 'firefly:import-beneficiaries
                            {file : Path to beneficiaries.json}
                            {--dry-run : Show what would be created without making changes}';

    public function handle(): int
    {
        $file = (string) $this->argument('file');

        if (!file_exists($file) || !is_readable($file)) {
            $this->error(sprintf('File not found or not readable: %s', $file));

            return 1;
        }

        $raw = file_get_contents($file);
        if (false === $raw) {
            $this->error('Could not read file.');

            return 1;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !array_key_exists('beneficiaries', $data)) {
            $this->error('Invalid JSON: expected a top-level "beneficiaries" array.');

            return 1;
        }

        $beneficiaries = $data['beneficiaries'];
        $dryRun        = (bool) $this->option('dry-run');

        /** @var AccountRepositoryInterface $repo */
        $repo = app(AccountRepositoryInterface::class);
        $repo->setUser(auth()->user() ?? \FireflyIII\User::first());

        $expenseType = AccountType::where('type', 'Expense account')->first();
        if (null === $expenseType) {
            $this->error('Could not find "Expense account" type in database.');

            return 1;
        }

        $existingAccounts = Account::where('account_type_id', $expenseType->id)
            ->whereNull('deleted_at')
            ->pluck('name')
            ->map(fn (string $n): string => mb_strtolower(trim($n)))
            ->toArray();

        $created  = 0;
        $skipped  = 0;

        // Pre-scan for duplicate names within the file so we can disambiguate
        $nameCounts = [];
        foreach ($beneficiaries as $ben) {
            $key = mb_strtolower($this->cleanName($ben['name'] ?? ''));
            $nameCounts[$key] = ($nameCounts[$key] ?? 0) + 1;
        }

        foreach ($beneficiaries as $ben) {
            $name           = $this->cleanName($ben['name'] ?? '');
            $classification = strtoupper(trim($ben['classification'] ?? 'INDIVIDUAL'));
            $subType        = strtoupper(trim($ben['subType'] ?? ''));
            $nickName       = trim($ben['nickName'] ?? '');

            if ('' === $name) {
                $this->warn('Skipping entry with empty name.');
                ++$skipped;

                continue;
            }

            // Disambiguate duplicate names by appending bank name
            if (($nameCounts[mb_strtolower($name)] ?? 0) > 1) {
                $bankName = trim($ben['instrument']['institution']['name'] ?? '');
                if ('' !== $bankName) {
                    $name = sprintf('%s (%s)', $name, $this->cleanName($bankName));
                }
            }

            if (in_array(mb_strtolower($name), $existingAccounts, true)) {
                $this->line(sprintf('  <comment>SKIP</comment>  "%s" (already exists)', $name));
                ++$skipped;

                continue;
            }

            $iban          = $this->extractIban($ben);
            $bic           = $this->extractBic($ben);
            $accountNumber = $this->extractAccountNumber($ben, $iban);
            $notes         = $this->buildNotes($ben, $classification, $subType, $nickName);

            if ($dryRun) {
                $this->info(sprintf('  [DRY-RUN] Would create: "%s" (%s) IBAN=%s', $name, $classification, $iban ?: 'n/a'));

                continue;
            }

            $accountData = [
                'name'              => $name,
                'account_type_name' => 'expense',
                'account_type_id'   => null,
                'currency_code'     => $this->extractCurrency($ben),
                'currency_id'       => 0,
                'iban'              => $iban,
                'BIC'               => $bic,
                'account_number'    => $accountNumber,
                'active'            => true,
                'include_net_worth' => false,
                'virtual_balance'   => null,
                'order'             => 0,
                'account_role'      => null,
                'opening_balance'   => null,
                'opening_balance_date' => null,
                'cc_type'           => null,
                'cc_monthly_payment_date' => null,
                'notes'             => $notes,
                'interest'          => null,
                'interest_period'   => null,
            ];

            try {
                $account = $repo->store($accountData);
                $this->info(sprintf('  <info>CREATED</info>  #%d "%s" (%s)', $account->id, $name, $classification));
                $existingAccounts[] = mb_strtolower($name);
                ++$created;
            } catch (\Exception $e) {
                $this->error(sprintf('  FAILED  "%s": %s', $name, $e->getMessage()));
                Log::error(sprintf('ImportBeneficiaries failed for "%s": %s', $name, $e->getMessage()));
            }
        }

        $this->newLine();
        if ($dryRun) {
            $this->comment(sprintf('Dry run complete. %d would be created, %d skipped.', count($beneficiaries) - $skipped, $skipped));
        } else {
            $this->info(sprintf('Done. Created: %d, Skipped: %d.', $created, $skipped));
        }

        return 0;
    }

    /**
     * Title-case a name for consistency (e.g. "JOHN DOE" → "John Doe").
     * Single-word all-caps entries ≤3 chars (acronyms like "NST") stay uppercase.
     */
    private function cleanName(string $name): string
    {
        $name  = trim($name);
        $name  = preg_replace('/\s+/', ' ', $name) ?? $name;
        $words = explode(' ', $name);

        $result = [];
        foreach ($words as $word) {
            if (mb_strtoupper($word) === $word && mb_strlen($word) > 3) {
                $result[] = mb_convert_case($word, MB_CASE_TITLE);
            } else {
                $result[] = $word;
            }
        }

        return implode(' ', $result);
    }

    private function extractIban(array $ben): ?string
    {
        $number = $ben['instrument']['number'] ?? null;
        if (null === $number) {
            return null;
        }

        $number = strtoupper(trim($number));

        // IBANs start with 2 letters followed by 2 digits
        if (preg_match('/^[A-Z]{2}\d{2}/', $number)) {
            return $number;
        }

        return null;
    }

    private function extractBic(array $ben): ?string
    {
        $routingCodes = $ben['instrument']['institution']['routingCodes'] ?? [];
        foreach ($routingCodes as $rc) {
            if ('BIC' === strtoupper($rc['scheme'] ?? '')) {
                return strtoupper(trim($rc['code']));
            }
        }

        return null;
    }

    private function extractAccountNumber(array $ben, ?string $iban): ?string
    {
        $number = $ben['instrument']['number'] ?? null;
        if (null === $number) {
            return null;
        }

        $number = trim($number);

        // If the number is already used as IBAN, don't duplicate it as account number
        if (null !== $iban) {
            return null;
        }

        return $number;
    }

    private function extractCurrency(array $ben): string
    {
        $tags = $ben['tags'] ?? [];
        foreach ($tags as $tag) {
            if (str_starts_with($tag, 'currency:')) {
                return strtoupper(substr($tag, 9));
            }
        }

        return 'AED';
    }

    private function buildNotes(array $ben, string $classification, string $subType, string $nickName): string
    {
        $lines = [];

        $lines[] = sprintf('Type: %s', 'ORGANIZATION' === $classification ? 'Organization' : 'Individual');

        $transferType = match ($subType) {
            'INTERNAL'      => 'Internal (same bank — Emirates NBD)',
            'LOCAL'         => 'Local (UAE bank transfer)',
            'INTERNATIONAL' => 'International transfer',
            default         => $subType,
        };
        $lines[] = sprintf('Transfer type: %s', $transferType);

        if ('' !== $nickName && mb_strtolower($nickName) !== mb_strtolower($ben['name'] ?? '')) {
            $lines[] = sprintf('Nick: %s', $nickName);
        }

        $bankName = $ben['instrument']['institution']['name'] ?? null;
        if ($bankName) {
            $lines[] = sprintf('Bank: %s', trim($bankName));
        }

        $city = $ben['instrument']['institution']['address']['city']['name']
             ?? $ben['address']['city']['name']
             ?? null;
        $country = $ben['instrument']['institution']['address']['country']['name']
                ?? $ben['address']['country']['name']
                ?? null;
        if ($city || $country) {
            $lines[] = sprintf('Location: %s', implode(', ', array_filter([$city, $country])));
        }

        $remarks = $ben['additionalInfo']['remarks'] ?? null;
        if ($remarks && '' !== trim($remarks)) {
            $lines[] = sprintf('Usual purpose: %s', trim($remarks));
        }

        $lines[] = sprintf('Emirates NBD beneficiary ID: %s', $ben['id'] ?? 'unknown');

        return implode("\n", $lines);
    }
}
