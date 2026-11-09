# Importing bank exports

Reached from [SKILL.md](SKILL.md) step 2. The user drops export files in the repo root. `.gitignore` already covers the usual names; don't commit them.

## Exports per bank

| Bank (account) | File | Notes |
|---|---|---|
| Emirates NBD (#1) | JSON API response | Import this first: card bill payments become NBD → card transfers from this export |
| Emirates Islamic card (#49) | JSON, same format as NBD | **The portal pages at 300 rows.** A long period arrives as several parts. Check that the parts join with no gap and no overlapping IDs, then import every part |
| Mashreq card (#50) | JSON (or CSV) | `{"data":{"transactions":[…]}}` wrappers are accepted as-is |
| FAB card (#51) | CSV, "Download Transactions" | The web API is app-layer encrypted, so use the CSV. **The Unbilled tab is not in the CSV.** Add unbilled items by hand, tagged `fab-unbilled`, with external_id `fab-unbilled-<TxnNo>`. Delete that copy once the item shows up in a later CSV |

Before importing, check each file: account / card number, date range, row count. Then report any gap longer than ~3 days to the user.

## Running the import

1. `php artisan firefly:backup`.
2. Run a **dry run** per bank, NBD first, and read the summary:
   ```bash
   php artisan firefly:bank-import enbd nbd-aug-oct.json nbd-nov.json --dry-run
   php artisan firefly:bank-import ei ei-part-1.json ei-part-2.json ei-part-3.json --dry-run
   php artisan firefly:bank-import mashreq mashreq.json --dry-run
   php artisan firefly:bank-import fab fab.csv --dry-run
   ```
   This is the same pipeline as the Bank Import pages (`BankImportService`): dedupe by bank ID, auth → settled amount refresh, rules fired per transaction.
3. Look at every `check` line, then run the same commands without `--dry-run`.

## Built into the parsers (no manual fixing needed)

- Card bill payments are counted once: the card-side copy (EI `PAYMENT` with no date, FAB `INST…` / `… CARD PMT`, Mashreq payment credits) is skipped.
- `REV RMA …` credits (cancelled card payments bouncing back) are skipped: not income.
- `FAILED` / `CANCELLED` / `REVERSED` / `REFUNDED` rows are skipped.
- Pending holds older than 21 days are skipped (expired pre-auths, e.g. Bird scooters).

## Still needs a human (reported as `check` lines)

- **`possible-duplicate`:** a pending hold with a settled charge of the same amount within 10 days. Usually the same purchase with a different ID. Delete the hold if so, and keep it if it's a genuine second purchase.
- **`unidentified-card`:** an NBD card payment to a card that isn't in Firefly ("card ending 4533"), or with no card number at all ("RMA TT REF …"). Ask the user whose card it is. If it's actually one of the 4 cards, convert it to a transfer and add the last 4 digits to `EnbdParser::detectCreditCardFromNarrations()`.
- **Investment withdrawals** (e.g. Baraka) are money moved, not income. Tag `investment-withdrawal`.

**Done when:** each file's dry run and real run agree, there are 0 failures, and every `check` line has been resolved with the user.
