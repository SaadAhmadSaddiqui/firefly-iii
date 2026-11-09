# Applying changes in Firefly III

Reached from [SKILL.md](SKILL.md) step 6, or whenever you write to Firefly. Database: PostgreSQL (credentials are in `.env`; stay out of that file and boot Laravel instead).

## Entity model

- **Budgets:** caps on variable spending. Use the existing budget names: list them from the `budgets` table instead of inventing near-duplicates. Each month's limits have been set by a one-off command (`app/Console/Commands/Tools/Setup<Month>Budget.php`, e.g. `firefly:setup-july-budget --dry-run`). Copy the latest one for a new month.
- **Subscriptions (Bills):** services that charge automatically (Netflix, Claude, HighLevel). The bill's `match` is always `MIGRATED_TO_RULES`, and `date` is a historical anchor. Linking happens through a **strict** rule (AND logic) in the "Subscription Rules" group: `description_contains` + `amount_more` / `amount_less` → `link_to_bill`. When the bank changes the descriptor (e.g. "HIGHLEVEL LLC"), the rule stops matching, so fix the trigger.
- **Recurring transactions:** payments the user makes themselves on a schedule (dad, rent, car loan, gym). Never use them for subscriptions.
- **Piggy banks:** savings goals for large periodic costs (Rent Fund, trips). They're linked to accounts through the `account_piggy_bank` pivot, and optionally to a budget (`budget_id`).

## How to write

- Take a backup first (`php artisan firefly:backup`).
- Use the app's repositories and factories from a scratch script that boots Laravel: `BudgetRepository::store`, `TagFactory::findOrCreate`, `TransactionGroupFactory::create`, `TransactionGroupDestroyService::destroy`. Budget and tag links are `$journal->budgets()->sync([...])` and `$journal->tags()->syncWithoutDetaching([...])`.
- Wrap each batch in `DB::beginTransaction()`. Run it with rollback first and print the counts. Commit only when they match what you told the user.
- Afterwards, verify with a read-back query rather than trusting the script's own counters.
