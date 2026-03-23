# Monthly Budget Plan — Prompt Template

Copy the prompt below, fill in the blanks, and send it to the AI. The skill file handles the rest.

---

## The Prompt

```
Create my monthly budget plan for [MONTH YEAR].

## My Financial Situation

- **Current bank balance (Emirates NBD):** AED [AMOUNT]
  (This is my total balance RIGHT NOW — salary already received, do NOT add salary.)
- **Cash on hand:** AED [AMOUNT or "none"]
- **Credit card balances:**
  - EI RTA CC: AED [AMOUNT or "0"]
  - Mashreq CC: AED [AMOUNT or "0"]
  - FAB CC: AED [AMOUNT or "0"]
- **Budget period:** [START DATE] to [END DATE] (payday to payday)

## Already Paid Today

List any payments that already went through today (deduct from balance):
- [e.g., Spotify AED 41.24 from Emirates NBD]
- [e.g., Noon AED 45.08 from EI card — transferred to clear]
- [or "Nothing paid yet today"]

## Large Upcoming Obligations This Period

- [e.g., Quarterly rent AED 14,500 due in March]
- [e.g., Car insurance AED 3,000 due next week]
- [or "None beyond the usual"]

## Fixed Monthly Obligations (confirm or update)

- Dad transfer: AED 5,000 (mandatory) — [YES still active / NO stopped]
- Donations (Droplets of Mercy + Impact Guru): AED ~384 — [CAN pause this month / CANNOT pause]
- GymNation (2 people): AED 400 — [Still active / Cancelled]

## Subscription Changes

Active subscriptions from last month that have CHANGED:
- [e.g., "Cancelled Netflix"]
- [e.g., "Paused Audible"]
- [e.g., "New subscription: Claude Pro AED 75/month"]
- [or "No changes — same as last month"]

## Goals or Constraints This Month

- [e.g., "Save AED 2,000 for emergency fund"]
- [e.g., "Wife's birthday — need AED 500 for gift"]
- [e.g., "Tight month, cut everything possible"]
- [e.g., "Normal month, reasonable budget"]

## Special Notes

- [Anything else: expected one-off expenses, income changes, etc.]
- [or "Nothing special"]

---

Analyze my last 3 months of transactions using the spending report command,
categorize all spending by merchant, and create a detailed budget plan
document (save to storage/budget-plans/).

Include a Firefly III changes section at the end listing what budgets,
subscriptions, rules, recurring transactions, or piggy banks I should
add, update, or remove — as a checklist I can work through myself.

Match the format and depth of the March 2026 budget plan.
```

---

## What Happens Next

The AI will:

1. Run `php artisan firefly:spending-report --months=3` to pull transaction data
2. Categorize every merchant into spending categories
3. Generate a detailed `.md` budget document with:
   - Financial snapshot
   - Full category breakdown with per-merchant tables and reasoning
   - Budget allocation table with % cuts from average
   - ASCII spending chart
   - Category-by-category reasoning
   - Buffer analysis and further-cut levers
   - Firefly III changes checklist (what to add/update/remove)

## Tips

- **Be honest about cash on hand** — it's your safety net, not spending money
- **List ALL subscription changes** — paused, cancelled, new ones. The AI checks against last month's data but your confirmation prevents errors.
- **Mention any unusual expenses coming** — car repair, medical procedure, travel. These need to be carved out of the budget.
- **"Tight month" vs "normal month"** matters — tight months freeze shopping and activities entirely. Normal months give modest allowances.
