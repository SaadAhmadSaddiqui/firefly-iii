---
name: monthly-budget-plan
description: Budgeting for this Firefly III instance. Use for importing bank exports, categorizing transactions into budgets, reviewing where money went, or writing next month's budget plan with budget / subscription / piggy-bank changes.
---

# Monthly Budget Plan (Firefly III)

This repo is a personal Firefly III fork with custom bank importers and budget tooling. The user hands you bank exports and their situation. You keep the books accurate and produce a monthly plan they can live by.

Three branches, usually run in this order. Start at the first one whose completion criterion is not yet met:

1. **Import:** Firefly is missing recent transactions → [IMPORT.md](IMPORT.md)
2. **Categorize:** imported withdrawals lack a budget → step 3 below
3. **Plan:** the user wants a budget for the coming month → steps 4–6 below

This file is the single source of truth. `.claude/skills/` and `.cursor/skills/` only hold pointers to it.

## Steps

### 1. Freshness check

Query the latest `transaction_journals.date` per asset account (#1, #49, #50, #51). Tell the user the last imported date per account, and ask for exports from that date onward.
**Done when:** the user knows the exact date range to export.

### 2. Import

Follow [IMPORT.md](IMPORT.md).
**Done when:** every export file is imported, the dry-run and the real run agree, and there are 0 failures. Every known double-count quirk has been handled.

### 3. Categorize

Assign exactly one budget to every withdrawal in the period. In order:

1. Budgets your existing rules already set (Talabat, GymNation, subscriptions) stand.
2. **History match:** normalize the destination account name and take the budget it has most often had before the period.
3. New merchants: classify by name, location and currency, and web-search cryptic descriptors. Mark each one with a confidence (high / medium / low) and a one-line reason.
4. Gather what you cannot resolve into a short numbered **question list**. Ask once, with date, amount, card and merchant for each.

Tag one-offs (`one-off`, plus a trip or event tag like `canada-trip-2026`) so they can be separated from everyday spending later.
Write proposals to `storage/budget-plans/<PERIOD>_IMPORT_REVIEW.md`. Apply them only after the user answers. Apply in one DB transaction: dry run first, then real.
**Done when:** a query shows 0 withdrawals in the period without a budget and 0 with more than one, and the review file reflects what was applied.

### 4. Gather plan inputs

Use [prompt-template.md](prompt-template.md) as the questionnaire. Skip anything the user already told you.
**Done when:** you have the NBD balance, each card's outstanding balance, cash on hand, payments already made today, upcoming large obligations, subscription changes, and this month's goals.

### 5. Write the plan

Pull data with `php artisan firefly:spending-report --from=YYYY-MM-DD --to=YYYY-MM-DD`. Use 3 months by default, and include actuals by Firefly budget.
Save to `storage/budget-plans/<MONTH>_BUDGET_<YEAR>.md`. Match the structure and depth of the most recent plan in that folder: an honest review of last month (plan vs actual), financial snapshot, allocation table with reasoning, cash-flow calendar, buffer analysis, biggest levers, and a Firefly changes checklist.
**Done when:** every line in the allocation table has a figure and a reason, and income minus obligations minus allocations reconciles to the stated buffer.

### 6. Apply Firefly changes

The plan ends with a checklist: budget limits, subscriptions, recurring transactions, piggy banks. After the user approves it, apply it yourself. Mechanics are in [FIREFLY.md](FIREFLY.md).
**Done when:** each checklist item is applied, or explicitly left for the user, and you've confirmed it in the DB.

## Key facts (as of Nov 2026, confirm drift in step 4)

| Fact | Value |
|---|---|
| Currency / timezone | AED / Asia/Dubai |
| Asset accounts | Emirates NBD debit #1 · EI RTA card #49 · Mashreq card #50 · FAB card #51 (card ends 9267) · Cash Wallet #5 |
| Salary | Deel AE FZE, ~AED 25,724.42 on the ~27th–28th. **It funds the next month** |
| Other income | NST Media FZC freelance payouts (irregular, tag `business-income`) |
| Dad | AED 5,000/month to Anees Ahmad (mandatory; sometimes + small extras) |
| Rent | AED 14,500 quarterly to Mohanadasan Kuttath, due Jan 1 / Apr 1 / Jul 1 / Oct 1 (often paid the day before). Each quarter is funded by the **3 salaries before it**, one 4,833 share each (Jan 1 ← Oct 28 + Nov 27 + Dec 27). Rent Fund piggy tracks it. Each October's renewal adds ~3k (Digital Sharjah fees + agent commission) |
| Car loan | AED 1,280/month from Dec 1, 2026, debited from Emirates NBD. Tracked as the "Car Loan" bill + budget |
| Wife | Budgets named "Bashaair's …". Transfers from her show as "Wajiha Ahmad". Shopping cap AED 1,000/month |
| Brother | Studies in Kazakhstan. Occasionally uses the Mashreq card or gets Paysend transfers (→ Family Usage / Family Transfer) |
| Fixed-ish | GymNation 2 × 197 · Droplets of Mercy 279.51 · Referral commission ~300 (Mohammad Dohadwala, irregular) |
| Goals | Kazakhstan trip ~Mar 2027, Georgia ~May 2027 (tentative). Italy is off. OSAP repayment to wife's mom ~Mar/Apr 2027 |

## Lessons (baked-in corrections)

1. The balance the user states already includes salary. The next salary belongs to next month.
2. Treat cash on hand as buffer, never as spending money.
3. Ask about paused, cancelled and new subscriptions every time. Charges drift (ChatGPT went 80 → 400). Short-drama app passes and mobile-game buys are `one-off`, never subscriptions.
4. Settle credit cards first and ask about payments already made today. FAB has charged late fees twice (Sep and Nov 2026), so always put the FAB due date in the cash-flow calendar.
5. A subscription is a Bill linked by a strict Rule. A payment the user makes themselves is a Recurring Transaction. Details are in [FIREFLY.md](FIREFLY.md).
6. APPLE.COM/BILL covers several services (iCloud personal / wife, YouTube Premium, Apple Developer yearly ~387). Tell them apart by amount.
7. Report everyday spending separately from one-offs (car, rent, renewals, trips). The user judges the month by the everyday number.
