---
name: generate-budget-plan
description: Generate monthly budget plans from Firefly III transaction data using a free local LLM (Ollama). Use when the user asks to create a budget plan, generate a spending analysis, plan next month's budget, or asks about budget generation with a free LLM.
---

# Generate Budget Plan

Generate detailed monthly budget plans from your Firefly III transaction history using a completely free LLM (no API costs).

## Prerequisites

1. **Firefly III** running and accessible (default: `http://localhost`)
2. **Firefly III Personal Access Token** -- generate at Profile > OAuth > Personal Access Tokens
3. **One free LLM backend**:
   - **Ollama via Docker** (recommended, default) -- runs automatically via `docker-compose up`, no manual install
   - **Ollama on host** -- install from https://ollama.com for native Metal/GPU acceleration
   - **Google Gemini free tier** -- cloud, needs free API key
   - **Groq free tier** -- cloud, needs free API key

> **Docker users**: Ollama runs as a container automatically. The model set in `BUDGET_LLM_MODEL` (default: `llama3.1`) is auto-pulled on first `docker-compose up`. No extra setup needed.

## Quick Start

### Step 1: Set environment variables

```bash
export FIREFLY_URL="http://localhost"
export FIREFLY_TOKEN="your-personal-access-token"

# For Ollama (default, no extra vars needed -- just have ollama running)
# For Gemini: export GEMINI_API_KEY="your-free-key"
# For Groq:   export GROQ_API_KEY="your-free-key"
```

### Step 2: Extract transaction data

```bash
python3 .cursor/skills/generate-budget-plan/scripts/extract_data.py \
  --months 3 \
  --salary 25724 \
  --currency AED \
  --goals "Build 3-month emergency fund" "Keep credit cards at zero" \
  --output /tmp/firefly_data.json
```

Key flags:
- `--months N` : how many months of history to analyze (default: 3)
- `--salary X` : monthly salary for budget calculations
- `--currency CODE` : primary currency (default: AED)
- `--goals "..." "..."` : financial goals to incorporate
- `--end-date YYYY-MM-DD` : end of analysis period (default: today)

### Step 3: Generate the budget plan

```bash
python3 .cursor/skills/generate-budget-plan/scripts/generate_plan.py \
  /tmp/firefly_data.json \
  --backend ollama \
  --model llama3.1 \
  --context "Quarterly rent due this month. Wife's personal care products count as groceries." \
  --output storage/budget-plans/APRIL_BUDGET_2026.md
```

Key flags:
- `--backend` : `ollama` (default), `gemini`, or `groq`
- `--model` : model name (defaults: llama3.1, gemini-2.0-flash, llama-3.3-70b-versatile)
- `--context` : extra info the LLM needs (obligations, life changes, etc.)
- `--output` : save to file (default: stdout)

### One-liner (pipe mode)

```bash
python3 .cursor/skills/generate-budget-plan/scripts/extract_data.py \
  --months 3 --salary 25724 --currency AED \
  | python3 .cursor/skills/generate-budget-plan/scripts/generate_plan.py - \
  --output storage/budget-plans/$(date +%B_%Y | tr '[:lower:]' '[:upper:]')_BUDGET.md
```

## Agent Workflow

When the user asks to generate a budget plan, follow this workflow:

1. **Check prerequisites**: Verify `FIREFLY_URL` and `FIREFLY_TOKEN` are set. Check if Ollama is running (`curl -s http://localhost:11434/api/tags`). If not set up, point the user to [setup.md](setup.md).

2. **Ask for parameters**: Salary, currency, number of months to analyze, any specific goals or context.

3. **Extract data**: Run `extract_data.py` with the user's parameters.

4. **Generate plan**: Run `generate_plan.py` with the extracted data.

5. **Save output**: Write to `storage/budget-plans/MONTH_YEAR_BUDGET.md`.

6. **Review**: Read the generated plan, check that numbers look reasonable against the extracted data, and present a summary to the user.

## Recommended Ollama Models

| Model | RAM Needed | Quality | Speed |
|-------|-----------|---------|-------|
| llama3.1:8b | ~5 GB | Good | Fast |
| qwen2.5:14b | ~10 GB | Better | Medium |
| mistral:7b | ~5 GB | Good | Fast |
| llama3.1:70b | ~40 GB | Best | Slow |

For budget plans, `qwen2.5:14b` offers the best balance of structured output quality and resource usage. If RAM is limited, `llama3.1:8b` works well.

## Customization

### Prompt Template

Edit `prompt-template.md` to customize the budget plan format, add/remove sections, or change the analysis style. The template uses `{{PLACEHOLDER}}` syntax for data insertion.

### Adding New LLM Backends

Add a new `call_<backend>()` function in `generate_plan.py` following the pattern of existing backends. The function takes `(prompt, model, api_key_or_url)` and returns the generated text.
