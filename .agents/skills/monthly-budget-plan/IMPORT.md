# Importing bank exports

Reached from [SKILL.md](SKILL.md) step 2. The user drops export files in the repo root. `.gitignore` already covers the usual names; don't commit them.

## Exports per bank

| Bank (account) | File | Parser | Gotchas |
|---|---|---|---|
| Emirates NBD (#1) | JSON API response (`nbd-transactions-*.json`) | `EnbdParser` | Amounts are sometimes nested (`{"source","parsedValue"}`); the parser handles this |
| Emirates Islamic card (#49) | JSON, same format as NBD (`ei-transactions-*.json`) | `EnbdParser` | **The portal pages at 300 rows.** A 3-month export arrives as several parts. Check that the parts join with no gap and no overlapping IDs |
| Mashreq card (#50) | JSON (`mashreq-*.json`) | `MashreqParser` (`format=json`) | Some parts come wrapped as `{"data":{"transactions":[…]}}`; unwrap to `{"transactions":[…]}` before parsing |
| FAB card (#51) | CSV "Download Transactions" (`fab-transactions-*.csv`) | `FabParser` | The portal's API responses are app-layer encrypted, so use the CSV. **The Unbilled tab is not in the CSV.** Add unbilled items by hand, tagged `fab-unbilled`, with external_id `fab-unbilled-<TxnNo>`. Delete that copy once the item shows up in a later CSV |

Before importing, check each file: the account / card number, the date range, the row count and the statuses (`SETTLED`, `AUTHORIZED`, `REVERSED`, …). Then report any gap longer than ~3 days to the user.

## Running the import

1. `php artisan firefly:backup`. The archive goes to `storage/backups/`.
2. Drive the same code path the web UI uses (`/bank-import/{enbd,mashreq,fab}`). Write a scratch PHP script that boots Laravel, logs in user 1, runs the right parser, and calls `ImportController::executeImport($transactions, $dryRun)` by reflection. That method handles external-id dedupe, auth→settle amount updates and rule firing.
3. Run it on a **dry run** (rolled back) first, apply the fixes below, then run it for real. Import NBD first, so card-payment transfers exist before the card files are processed.

`local.ERROR: Could not find a transaction currency with code "UAE Dirham"` floods the log. It's harmless noise; filter it out.

## Double-count fixes (check every import)

- **Card payments recorded twice.** NBD `CARD_PAYMENT` debits become transfers NBD → card. The EI export *also* emits each payment (no `date`, only `valueDate`) as its own transfer, so drop the EI-side ones. FAB and Mashreq parsers skip their mirror credits only when they recognize the description: `FT…-FAB CARD PMT` slips through and must be dropped by hand.
- **Unidentified NBD card payments.** If the narration has no known card digits, the parser emits a withdrawal named "Debit Card Settlement". Match it by amount and date to a card-side payment and convert it to a transfer to that card. If nothing matches, it is a card outside Firefly; ask the user whose it is.
- **Fake income from cancelled payments.** NBD `REV RMA …` credits reverse a `CANCELLED` card payment. They aren't income; drop them.
- **Holds that never settled.** Card rows still `AUTHORIZED` weeks later (e.g. Bird scooter pre-auths) never post; delete them. An `AUTHORIZED` row with a `SETTLED` twin of the same amount within ~10 days but a different ID is a duplicate; keep one.
- **Investment withdrawals** (Baraka) are money moved, not income. Tag `investment-withdrawal`.

**Done when:** a per-account count of the new rows matches the files minus the skipped rows, and no card payment appears twice.
