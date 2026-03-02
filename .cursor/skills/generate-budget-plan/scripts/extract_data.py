#!/usr/bin/env python3
"""
Extract transaction data and account balances from Firefly III API.
Outputs a structured JSON summary for budget plan generation.
"""

import argparse
import json
import os
import sys
from collections import defaultdict
from datetime import datetime, timedelta
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen


def api_request(base_url: str, token: str, endpoint: str, params: dict | None = None) -> dict:
    url = f"{base_url}/api/v1/{endpoint}"
    if params:
        url += "?" + urlencode(params)
    req = Request(url)
    req.add_header("Authorization", f"Bearer {token}")
    req.add_header("Accept", "application/vnd.api+json")
    req.add_header("Content-Type", "application/json")
    with urlopen(req) as resp:
        return json.loads(resp.read().decode())


def paginate_all(base_url: str, token: str, endpoint: str, params: dict | None = None) -> list:
    """Fetch all pages from a paginated API endpoint."""
    params = dict(params or {})
    params["limit"] = 100
    page = 1
    all_data = []
    while True:
        params["page"] = page
        resp = api_request(base_url, token, endpoint, params)
        data = resp.get("data", [])
        if not data:
            break
        all_data.extend(data)
        meta = resp.get("meta", {}).get("pagination", {})
        total_pages = meta.get("total_pages", 1)
        if page >= total_pages:
            break
        page += 1
    return all_data


def get_accounts(base_url: str, token: str) -> list[dict]:
    """Get all asset and liability accounts with current balances."""
    accounts = []
    for acct_type in ["asset", "liabilities"]:
        data = paginate_all(base_url, token, "accounts", {"type": acct_type})
        for item in data:
            attrs = item.get("attributes", {})
            accounts.append({
                "id": item["id"],
                "name": attrs.get("name", ""),
                "type": attrs.get("type", ""),
                "role": attrs.get("account_role", ""),
                "currency_code": attrs.get("currency_code", ""),
                "current_balance": float(attrs.get("current_balance", 0)),
                "active": attrs.get("active", True),
            })
    return accounts


def get_transactions(base_url: str, token: str, start_date: str, end_date: str) -> list[dict]:
    """Get all withdrawal transactions in the date range."""
    raw = paginate_all(base_url, token, "transactions", {
        "start": start_date,
        "end": end_date,
        "type": "withdrawal",
    })
    transactions = []
    for item in raw:
        attrs = item.get("attributes", {})
        for split in attrs.get("transactions", []):
            transactions.append({
                "date": split.get("date", "")[:10],
                "amount": abs(float(split.get("amount", 0))),
                "currency_code": split.get("currency_code", ""),
                "description": split.get("description", ""),
                "category_name": split.get("category_name", ""),
                "budget_name": split.get("budget_name", ""),
                "source_name": split.get("source_name", ""),
                "destination_name": split.get("destination_name", ""),
                "tags": split.get("tags", []),
            })
    return transactions


def get_budgets(base_url: str, token: str) -> list[dict]:
    """Get all budget definitions and their current limits."""
    raw = paginate_all(base_url, token, "budgets")
    budgets = []
    for item in raw:
        attrs = item.get("attributes", {})
        budgets.append({
            "id": item["id"],
            "name": attrs.get("name", ""),
            "active": attrs.get("active", True),
        })
    return budgets


def get_categories(base_url: str, token: str) -> list[dict]:
    """Get all categories."""
    raw = paginate_all(base_url, token, "categories")
    return [
        {"id": item["id"], "name": item.get("attributes", {}).get("name", "")}
        for item in raw
    ]


def get_piggy_banks(base_url: str, token: str) -> list[dict]:
    """Get savings goals (piggy banks)."""
    raw = paginate_all(base_url, token, "piggy-banks")
    goals = []
    for item in raw:
        attrs = item.get("attributes", {})
        goals.append({
            "name": attrs.get("name", ""),
            "target_amount": float(attrs.get("target_amount", 0) or 0),
            "current_amount": float(attrs.get("current_amount", 0) or 0),
            "percentage": float(attrs.get("percentage", 0) or 0),
            "active": attrs.get("active", True),
        })
    return goals


def get_bills(base_url: str, token: str) -> list[dict]:
    """Get recurring bills/subscriptions."""
    raw = paginate_all(base_url, token, "subscriptions")
    if not raw:
        raw = paginate_all(base_url, token, "bills")
    bills = []
    for item in raw:
        attrs = item.get("attributes", {})
        bills.append({
            "name": attrs.get("name", ""),
            "amount_min": float(attrs.get("amount_min", 0)),
            "amount_max": float(attrs.get("amount_max", 0)),
            "currency_code": attrs.get("currency_code", ""),
            "repeat_freq": attrs.get("repeat_freq", ""),
            "active": attrs.get("active", True),
        })
    return bills


def analyze_spending(transactions: list[dict]) -> dict:
    """Categorize and summarize spending patterns."""
    by_category = defaultdict(lambda: {"total": 0.0, "count": 0, "merchants": defaultdict(lambda: {"total": 0.0, "count": 0})})
    by_merchant = defaultdict(lambda: {"total": 0.0, "count": 0, "category": ""})
    by_month = defaultdict(float)

    for txn in transactions:
        cat = txn["category_name"] or "Uncategorized"
        merchant = txn["destination_name"] or "Unknown"
        amount = txn["amount"]
        month = txn["date"][:7]

        by_category[cat]["total"] += amount
        by_category[cat]["count"] += 1
        by_category[cat]["merchants"][merchant]["total"] += amount
        by_category[cat]["merchants"][merchant]["count"] += 1

        by_merchant[merchant]["total"] += amount
        by_merchant[merchant]["count"] += 1
        by_merchant[merchant]["category"] = cat

        by_month[month] += amount

    cat_summary = {}
    for cat, data in sorted(by_category.items(), key=lambda x: x[1]["total"], reverse=True):
        merchants_sorted = sorted(data["merchants"].items(), key=lambda x: x[1]["total"], reverse=True)
        cat_summary[cat] = {
            "total": round(data["total"], 2),
            "count": data["count"],
            "top_merchants": [
                {"name": m, "total": round(d["total"], 2), "count": d["count"]}
                for m, d in merchants_sorted[:15]
            ],
        }

    top_merchants = sorted(by_merchant.items(), key=lambda x: x[1]["total"], reverse=True)[:30]

    return {
        "by_category": cat_summary,
        "top_merchants": [
            {"name": m, "total": round(d["total"], 2), "count": d["count"], "category": d["category"]}
            for m, d in top_merchants
        ],
        "by_month": dict(sorted(by_month.items())),
        "total_spent": round(sum(t["amount"] for t in transactions), 2),
        "transaction_count": len(transactions),
    }


def main():
    parser = argparse.ArgumentParser(description="Extract Firefly III data for budget planning")
    parser.add_argument("--base-url", default=os.environ.get("FIREFLY_URL", "http://localhost"),
                        help="Firefly III base URL (default: $FIREFLY_URL or http://localhost)")
    parser.add_argument("--token", default=os.environ.get("FIREFLY_TOKEN", ""),
                        help="Personal Access Token (default: $FIREFLY_TOKEN)")
    parser.add_argument("--months", type=int, default=3,
                        help="Number of months of history to analyze (default: 3)")
    parser.add_argument("--end-date", default=None,
                        help="End date for analysis (YYYY-MM-DD, default: today)")
    parser.add_argument("--output", default=None,
                        help="Output JSON file path (default: stdout)")
    parser.add_argument("--salary", type=float, default=None,
                        help="Monthly salary amount for budget calculations")
    parser.add_argument("--currency", default="AED",
                        help="Primary currency code (default: AED)")
    parser.add_argument("--goals", nargs="*", default=[],
                        help="Financial goals, e.g. 'Save 5000 for emergency fund' 'Pay off credit card'")
    args = parser.parse_args()

    if not args.token:
        print("Error: Firefly III Personal Access Token required.", file=sys.stderr)
        print("Set FIREFLY_TOKEN env var or use --token flag.", file=sys.stderr)
        print("Generate one at: <your-firefly-url>/profile > OAuth > Personal Access Tokens", file=sys.stderr)
        sys.exit(1)

    end = datetime.strptime(args.end_date, "%Y-%m-%d") if args.end_date else datetime.now()
    start = end - timedelta(days=args.months * 30)
    start_str = start.strftime("%Y-%m-%d")
    end_str = end.strftime("%Y-%m-%d")

    print(f"Extracting data from {start_str} to {end_str}...", file=sys.stderr)

    try:
        accounts = get_accounts(args.base_url, args.token)
        print(f"  Found {len(accounts)} accounts", file=sys.stderr)

        transactions = get_transactions(args.base_url, args.token, start_str, end_str)
        print(f"  Found {len(transactions)} withdrawal transactions", file=sys.stderr)

        budgets = get_budgets(args.base_url, args.token)
        categories = get_categories(args.base_url, args.token)
        piggy_banks = get_piggy_banks(args.base_url, args.token)
        bills = get_bills(args.base_url, args.token)

        spending = analyze_spending(transactions)

    except HTTPError as e:
        print(f"API error: {e.code} {e.reason}", file=sys.stderr)
        if e.code == 401:
            print("Invalid or expired token. Generate a new one in Firefly III.", file=sys.stderr)
        sys.exit(1)
    except URLError as e:
        print(f"Connection error: {e.reason}", file=sys.stderr)
        print(f"Is Firefly III running at {args.base_url}?", file=sys.stderr)
        sys.exit(1)

    result = {
        "extraction_date": end_str,
        "analysis_period": {"start": start_str, "end": end_str, "months": args.months},
        "currency": args.currency,
        "salary": args.salary,
        "financial_goals": args.goals,
        "accounts": accounts,
        "spending_analysis": spending,
        "budgets": budgets,
        "categories": [c["name"] for c in categories],
        "savings_goals": piggy_banks,
        "recurring_bills": bills,
    }

    output = json.dumps(result, indent=2, ensure_ascii=False)
    if args.output:
        with open(args.output, "w") as f:
            f.write(output)
        print(f"Data written to {args.output}", file=sys.stderr)
    else:
        print(output)


if __name__ == "__main__":
    main()
