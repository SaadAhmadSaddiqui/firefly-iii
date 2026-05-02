---
name: monthly-budget-plan
description: Creates a comprehensive monthly budget plan for Firefly III by analyzing past transactions, categorizing spending, and recommending budget/subscription/rule changes. Use when the user asks to create a budget, plan monthly spending, or set up next month's budget.
---

# Monthly Budget Plan for Firefly III

## Overview

This skill produces a detailed monthly budget plan by:
1. Extracting and analyzing past transaction data from Firefly III's database
2. Categorizing all spending into defined categories with reasoning
3. Generating a markdown budget document in `storage/budget-plans/`
4. Recommending what budgets, subscriptions, rules, piggy banks, or recurring transactions to add, update, or remove in Firefly III

The initial Firefly III setup (budgets, subscriptions with rules, piggy banks, recurring transactions) was done via `app/Console/Commands/Tools/SetupMarchBudget.php`. That command does NOT need to be recreated each month. Instead, the budget document should include an actionable recommendations section listing what the user should change in Firefly III themselves.

The reference budget plan is at `storage/budget-plans/MARCH_BUDGET_2026.md`. Always match its structure, tone, and depth of analysis.

## Quick Start

When the user asks for a budget plan, follow the [prompt template](prompt-template.md) to gather the required inputs. If the user provides values directly, skip the questionnaire and proceed.

## Workflow

### Phase 1: Gather Inputs

Collect from the user (ask if not provided):

| Input | Example | Why |
|---|---|---|
| Current bank balance | AED 25,945 | This is the TOTAL available NOW — salary already received |
| Cash on hand | AED 2,800 | Buffer, last resort |
| Credit card balances | All at 0 | Need to know if CCs need settling |
| Budget period | Feb 25 – Mar 28 | Salary-to-salary, NOT calendar month |
| Large upcoming obligations | Rent AED 14,500 quarterly | Deducted off the top |
| Mandatory fixed transfers | AED 5,000 to dad | Cannot be cut |
| Paused/cancelled subscriptions | Audible, Starzplay paused | Affects subscription budget |
| Any payments already made today | Spotify AED 41.24 | Deducted from starting balance |
| Number of months to analyze | 3 | Default: 3 months lookback |

**Critical**: The balance the user gives you is their CURRENT total after salary. Do NOT add salary on top. Salary arrives ~27th of the PREVIOUS month and is already in the bank balance. The next salary (~27th-28th of the current month) is for NEXT month's budget, not this month's.

### Phase 2: Extract Transaction Data

Run the spending report command:

```bash
php artisan firefly:spending-report --months=3
```

This command lives at `app/Console/Commands/Tools/SpendingReport.php`. It queries all withdrawals (destination-side `amount > 0`) and outputs:
- All withdrawals listed chronologically
- Spending grouped by merchant (sorted by total, descending)
- Monthly totals

If the command doesn't exist, create it using the reference at `app/Console/Commands/Tools/SpendingReport.php`.

### Phase 3: Categorize & Analyze

Group merchants into these categories (add/remove as spending evolves):

| Category | What Goes Here |
|---|---|
| Personal Transfers | Transfers to family/friends (identify mandatory vs one-off) |
| Rent | Cheque payments, housing |
| Utilities | e&, SEWA (electricity/water), internet |
| Groceries | Noon Minutes, Amazon Grocery, Carrefour, supermarkets, **wife's hygiene products** |
| Food Delivery | Talabat, Wolt, delivery subscriptions |
| Dining Out | Restaurants, cafes, coffee shops |
| Business Tools | HighLevel, Google Workspace, AWS, professional SaaS |
| Personal Subscriptions | Apple, Netflix, Spotify, ChatGPT, Google One, PlayStation, etc. |
| Education | Preply, courses, exam prep |
| Transportation | Fuel (ADNOC, ENOC), taxis (Careem), parking |
| Fitness | GymNation (2 people = AED 400/month), gym extras |
| Donations | Droplets of Mercy, Impact Guru (check if pauseable) |
| Shopping | Amazon.ae, clothing, electronics (first to freeze) |
| Activities/Outings | Museums, events, game purchases (first to freeze) |
| Medical | Pharmacies, doctor visits |
| Grooming | Hairdressing (AED 100/month) |
| Government/Admin | Fines, visa, registration (usually one-time) |
| Bank Fees | Card annual fees, monthly charges |

For each category, compute: 3-month total, monthly average, transaction count, % of non-rent budget.

### Phase 4: Write the Budget Document

Save to `storage/budget-plans/[MONTH]_BUDGET_[YEAR].md`.

**Required sections** (match the reference document):

1. **Financial Snapshot** — Table showing bank balance, cash, CC balances, large obligations, available remainder
2. **Where Your Money Has Been Going** — Full category breakdown with per-merchant tables, transaction counts, monthly averages, and reasoning paragraphs
3. **Monthly Spending Summary** — Aggregated table with % of budget
4. **Rent/Large Obligation Strategy** — Explain piggy bank + recurring transaction approach
5. **Monthly Budget Allocation** — The core budget table with: category, budget amount, vs monthly avg (% change), and reasoning column
6. **ASCII bar chart** — Visual representation of where every dirham goes
7. **Category-by-Category Reasoning** — Detailed paragraph for each category explaining WHY the budget is set where it is
8. **Buffer Analysis** — What could eat into the emergency buffer
9. **Biggest Levers** — Ranked table of further cuts if needed
10. **Firefly III Changes** — Actionable recommendations for what to add, update, or remove (see below)

### Firefly III Changes Section (in the budget document)

The budget document must end with a concrete recommendations section. Compare what ALREADY exists in Firefly III (from the March setup and any subsequent changes) against what this month's budget requires, and list:

#### Budget Limits
- Which existing budgets need their monthly amount updated (e.g., "Groceries: AED 1,500 → AED 1,300")
- Any new budget categories to create
- Any budgets to deactivate (set amount to 0 or remove)

#### Subscriptions (Bills + Rules)
- New subscriptions the user signed up for (include: name, match keywords, amount range, anchor date)
- Subscriptions to deactivate or delete (cancelled/paused services)
- Existing subscriptions whose amount range needs updating (price changes)
- Remind: subscriptions use Bills with `match = 'MIGRATED_TO_RULES'` and a strict Rule in the "Subscription Rules" group

#### Recurring Transactions
- Any new recurring payments to add
- Any to deactivate (e.g., if donations get paused)
- Amount changes to existing ones

#### Piggy Banks
- Target/contribution updates (e.g., rent piggy bank reset for next quarter)
- New savings goals

Present these as a checklist the user can work through in the Firefly III UI.

## Key Facts (Hardcoded Context)

| Fact | Value |
|---|---|
| Currency | AED |
| Timezone | Asia/Dubai |
| Emirates NBD (main checking) | Account #1 |
| EI RTA Credit Card | Account #49 |
| Mashreq Credit Card | Account #50 |
| FAB Credit Card | Account #51 |
| Dad transfer (mandatory) | AED 5,000/month to Anees Ahmad |
| GymNation | 2 people, ~AED 400/month total |
| Grooming | AED 90/month |
| Wife's hygiene products | Included under Groceries, not separate |
| Rent | AED 14,500 quarterly (use Piggy Bank + Recurring) |
| Salary source | Deel AE FZE |
| Salary approx | AED 25,724 |
| Salary timing | Received ~27th of previous month, used for the NEXT calendar month |
| Database | PostgreSQL (credentials in .env) |

### Salary Timing (Critical)

Salary arrives around the **27th of the previous month** and funds the **next calendar month**.
For example, salary received on March 27 is the budget for April. Salary received on April 28
is the budget for May — do NOT include it in April's plan.

When the user states their bank balance at the start of a budget month (e.g., April 1), the
salary is **already included** in that balance. There is no additional salary expected during the
budget month. This means:
- No "cash flow warning" about waiting for salary
- The stated bank balance is the **total available** for the month
- All obligations, savings, and spending must fit within that balance
- Credit cards can be used freely during the month since the money to pay them off is already in the bank

## Firefly III Entity Concepts (for accurate recommendations)

When recommending changes, use the correct Firefly III terminology:

- **Budgets**: Variable spending caps (groceries, dining, transport). Fixed costs (utilities, subscriptions, gym) don't need budgets. Budgets use auto-budget monthly reset.
- **Subscriptions (Bills)**: Auto-charged services (Netflix, Spotify, HighLevel). Matched via Rules, not the bill's `match` field. Bill `match` is always `'MIGRATED_TO_RULES'`. Bill `date` must be a historical anchor. Rules must use `strict = true` (AND logic) with triggers: `description_contains` + `amount_more`/`amount_less`, and action: `link_to_bill`.
- **Recurring Transactions**: Payments the user makes themselves on a schedule (rent, dad transfer, gym, donations). NOT for subscriptions.
- **Piggy Banks**: Savings goals for large periodic costs (rent fund). Linked to accounts via pivot table, not directly to users.

## Lessons Learned (Baked-in Corrections)

These are mistakes made during the first budget plan that must NOT be repeated:

1. **Never add salary to the balance** — the user's stated balance IS the post-salary total
2. **GymNation is 2 people** — AED 400, not 200
3. **Subscriptions are Bills, not Recurring Transactions** — Recurring is for rent, dad transfer, gym, donations
4. **Always ask about paused/cancelled subs** — don't assume all are active
5. **Cash on hand = buffer** — account for it but don't spend it unless bank runs out
6. **Donations status varies** — always ask if they can be paused THIS month
7. **Credit cards must be settled first** — ask about outstanding balances
8. **Same-day payments** — ask what was paid today and deduct from starting balance
9. **New subscriptions need strict rules** — if recommending a new subscription, specify: name, match keywords, amount range, anchor date, and remind user to create a strict rule in the "Subscription Rules" group
10. **Apple subscriptions are multiple charges** — APPLE.COM/BILL covers iCloud (Personal), iCloud (Wife), YouTube Premium. Differentiate by amount range.

## Reference Files

- Budget document template: `storage/budget-plans/MARCH_BUDGET_2026.md`
- Initial setup command (for reference only): `app/Console/Commands/Tools/SetupMarchBudget.php`
- Spending report command: `app/Console/Commands/Tools/SpendingReport.php`
- Prompt template for user: [prompt-template.md](prompt-template.md)
