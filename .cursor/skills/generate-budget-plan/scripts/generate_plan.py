#!/usr/bin/env python3
"""
Generate a budget plan from extracted Firefly III data using a free LLM.

Supported backends (all free):
  - ollama    : Local models via Ollama (default, no internet needed)
  - gemini    : Google Gemini API free tier (needs GEMINI_API_KEY)
  - groq      : Groq free tier (needs GROQ_API_KEY)
"""

import argparse
import json
import os
import sys
from datetime import datetime
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

SCRIPT_DIR = Path(__file__).parent
SKILL_DIR = SCRIPT_DIR.parent

DEFAULT_OLLAMA_MODEL = "llama3.1"
DEFAULT_GEMINI_MODEL = "gemini-2.0-flash"
DEFAULT_GROQ_MODEL = "llama-3.3-70b-versatile"


def load_prompt_template() -> str:
    template_path = SKILL_DIR / "prompt-template.md"
    if not template_path.exists():
        print(f"Error: prompt template not found at {template_path}", file=sys.stderr)
        sys.exit(1)
    return template_path.read_text()


def build_prompt(template: str, data: dict, user_context: str = "") -> str:
    """Insert extracted data into the prompt template."""
    accounts_text = format_accounts(data.get("accounts", []))
    spending_text = format_spending(data.get("spending_analysis", {}))
    goals_text = format_goals(data.get("savings_goals", []), data.get("financial_goals", []))
    bills_text = format_bills(data.get("recurring_bills", []))
    period = data.get("analysis_period", {})
    currency = data.get("currency", "AED")
    salary = data.get("salary")

    prompt = template
    prompt = prompt.replace("{{EXTRACTION_DATE}}", data.get("extraction_date", datetime.now().strftime("%Y-%m-%d")))
    prompt = prompt.replace("{{PERIOD_START}}", period.get("start", ""))
    prompt = prompt.replace("{{PERIOD_END}}", period.get("end", ""))
    prompt = prompt.replace("{{PERIOD_MONTHS}}", str(period.get("months", 3)))
    prompt = prompt.replace("{{CURRENCY}}", currency)
    prompt = prompt.replace("{{SALARY}}", f"{salary:,.2f}" if salary else "Not provided")
    prompt = prompt.replace("{{ACCOUNTS}}", accounts_text)
    prompt = prompt.replace("{{SPENDING_ANALYSIS}}", spending_text)
    prompt = prompt.replace("{{GOALS}}", goals_text)
    prompt = prompt.replace("{{BILLS}}", bills_text)
    prompt = prompt.replace("{{TRANSACTION_COUNT}}", str(data.get("spending_analysis", {}).get("transaction_count", 0)))
    prompt = prompt.replace("{{TOTAL_SPENT}}", f"{data.get('spending_analysis', {}).get('total_spent', 0):,.2f}")
    prompt = prompt.replace("{{USER_CONTEXT}}", user_context or "No additional context provided.")
    return prompt


def format_accounts(accounts: list) -> str:
    if not accounts:
        return "No account data available."
    lines = []
    for a in accounts:
        if not a.get("active", True):
            continue
        balance = a["current_balance"]
        lines.append(f"- {a['name']} ({a['type']}): {a['currency_code']} {balance:,.2f}")
    return "\n".join(lines) or "No active accounts found."


def format_spending(analysis: dict) -> str:
    if not analysis:
        return "No spending data available."
    lines = []
    by_cat = analysis.get("by_category", {})
    for cat, data in by_cat.items():
        months_count = max(len(analysis.get("by_month", {})), 1)
        monthly_avg = data["total"] / months_count
        lines.append(f"\n### {cat}")
        lines.append(f"Total: {data['total']:,.2f} | Transactions: {data['count']} | Monthly avg: {monthly_avg:,.2f}")
        lines.append("| Merchant | Total | Count |")
        lines.append("|----------|-------|-------|")
        for m in data["top_merchants"]:
            lines.append(f"| {m['name']} | {m['total']:,.2f} | {m['count']} |")

    by_month = analysis.get("by_month", {})
    if by_month:
        lines.append("\n### Monthly Totals")
        for month, total in by_month.items():
            lines.append(f"- {month}: {total:,.2f}")

    return "\n".join(lines)


def format_goals(piggy_banks: list, text_goals: list) -> str:
    lines = []
    if piggy_banks:
        for g in piggy_banks:
            if not g.get("active", True):
                continue
            target = g["target_amount"]
            current = g["current_amount"]
            pct = g["percentage"]
            lines.append(f"- {g['name']}: {current:,.2f} / {target:,.2f} ({pct:.0f}% complete)")
    if text_goals:
        lines.append("\nUser-defined goals:")
        for goal in text_goals:
            lines.append(f"- {goal}")
    return "\n".join(lines) or "No savings goals defined."


def format_bills(bills: list) -> str:
    if not bills:
        return "No recurring bills found."
    lines = ["| Name | Min | Max | Frequency |", "|------|-----|-----|-----------|"]
    for b in bills:
        if not b.get("active", True):
            continue
        lines.append(f"| {b['name']} | {b['currency_code']} {b['amount_min']:,.2f} | {b['currency_code']} {b['amount_max']:,.2f} | {b['repeat_freq']} |")
    return "\n".join(lines)


# ---------------------------------------------------------------------------
# LLM backends
# ---------------------------------------------------------------------------

def call_ollama(prompt: str, model: str, base_url: str = "http://localhost:11434") -> str:
    """Call Ollama's local API."""
    payload = json.dumps({
        "model": model,
        "prompt": prompt,
        "stream": False,
        "options": {"temperature": 0.3, "num_predict": 8192},
    }).encode()

    req = Request(f"{base_url}/api/generate", data=payload)
    req.add_header("Content-Type", "application/json")

    try:
        with urlopen(req, timeout=300) as resp:
            result = json.loads(resp.read().decode())
            return result.get("response", "")
    except URLError as e:
        print(f"Ollama connection failed: {e}", file=sys.stderr)
        print("Is Ollama running? Start it with: ollama serve", file=sys.stderr)
        print(f"Is model '{model}' pulled? Run: ollama pull {model}", file=sys.stderr)
        sys.exit(1)


def call_gemini(prompt: str, model: str, api_key: str) -> str:
    """Call Google Gemini API (free tier)."""
    url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={api_key}"
    payload = json.dumps({
        "contents": [{"parts": [{"text": prompt}]}],
        "generationConfig": {"temperature": 0.3, "maxOutputTokens": 8192},
    }).encode()

    req = Request(url, data=payload)
    req.add_header("Content-Type", "application/json")

    try:
        with urlopen(req, timeout=120) as resp:
            result = json.loads(resp.read().decode())
            candidates = result.get("candidates", [])
            if candidates:
                parts = candidates[0].get("content", {}).get("parts", [])
                return parts[0].get("text", "") if parts else ""
            return ""
    except HTTPError as e:
        body = e.read().decode() if hasattr(e, "read") else ""
        print(f"Gemini API error {e.code}: {body}", file=sys.stderr)
        sys.exit(1)


def call_groq(prompt: str, model: str, api_key: str) -> str:
    """Call Groq API (free tier)."""
    url = "https://api.groq.com/openai/v1/chat/completions"
    payload = json.dumps({
        "model": model,
        "messages": [
            {"role": "system", "content": "You are an expert financial planner. Generate detailed, actionable budget plans in markdown format."},
            {"role": "user", "content": prompt},
        ],
        "temperature": 0.3,
        "max_tokens": 8192,
    }).encode()

    req = Request(url, data=payload)
    req.add_header("Content-Type", "application/json")
    req.add_header("Authorization", f"Bearer {api_key}")

    try:
        with urlopen(req, timeout=120) as resp:
            result = json.loads(resp.read().decode())
            choices = result.get("choices", [])
            return choices[0]["message"]["content"] if choices else ""
    except HTTPError as e:
        body = e.read().decode() if hasattr(e, "read") else ""
        print(f"Groq API error {e.code}: {body}", file=sys.stderr)
        sys.exit(1)


def generate(prompt: str, backend: str, model: str | None = None) -> str:
    """Route to the appropriate LLM backend."""
    if backend == "ollama":
        ollama_url = os.environ.get("OLLAMA_URL", "http://localhost:11434")
        return call_ollama(prompt, model or DEFAULT_OLLAMA_MODEL, ollama_url)
    elif backend == "gemini":
        api_key = os.environ.get("GEMINI_API_KEY", "")
        if not api_key:
            print("Error: GEMINI_API_KEY env var required for Gemini backend.", file=sys.stderr)
            print("Get a free key at: https://aistudio.google.com/app/apikey", file=sys.stderr)
            sys.exit(1)
        return call_gemini(prompt, model or DEFAULT_GEMINI_MODEL, api_key)
    elif backend == "groq":
        api_key = os.environ.get("GROQ_API_KEY", "")
        if not api_key:
            print("Error: GROQ_API_KEY env var required for Groq backend.", file=sys.stderr)
            print("Get a free key at: https://console.groq.com/keys", file=sys.stderr)
            sys.exit(1)
        return call_groq(prompt, model or DEFAULT_GROQ_MODEL, api_key)
    else:
        print(f"Unknown backend: {backend}. Use: ollama, gemini, groq", file=sys.stderr)
        sys.exit(1)


def main():
    parser = argparse.ArgumentParser(description="Generate a budget plan from Firefly III data using a free LLM")
    parser.add_argument("data_file", help="JSON file from extract_data.py (use - for stdin)")
    parser.add_argument("--backend", default=os.environ.get("BUDGET_LLM_BACKEND", "ollama"),
                        choices=["ollama", "gemini", "groq"],
                        help="LLM backend (default: $BUDGET_LLM_BACKEND or ollama)")
    parser.add_argument("--model", default=None,
                        help="Model name (default depends on backend)")
    parser.add_argument("--output", default=None,
                        help="Output markdown file (default: stdout)")
    parser.add_argument("--context", default="",
                        help="Additional context: obligations, upcoming expenses, life changes, etc.")
    args = parser.parse_args()

    if args.data_file == "-":
        data = json.load(sys.stdin)
    else:
        with open(args.data_file) as f:
            data = json.load(f)

    template = load_prompt_template()
    prompt = build_prompt(template, data, args.context)

    model_display = args.model or {
        "ollama": DEFAULT_OLLAMA_MODEL,
        "gemini": DEFAULT_GEMINI_MODEL,
        "groq": DEFAULT_GROQ_MODEL,
    }[args.backend]

    print(f"Generating budget plan via {args.backend} ({model_display})...", file=sys.stderr)
    print(f"Prompt size: ~{len(prompt)} chars", file=sys.stderr)

    result = generate(prompt, args.backend, args.model)

    if not result.strip():
        print("Error: LLM returned empty response.", file=sys.stderr)
        sys.exit(1)

    if args.output:
        with open(args.output, "w") as f:
            f.write(result)
        print(f"Budget plan written to {args.output}", file=sys.stderr)
    else:
        print(result)


if __name__ == "__main__":
    main()
