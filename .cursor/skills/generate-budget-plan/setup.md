# Free LLM Setup Guide

Four ways to run the budget plan generator. All free.

---

## Option 1: Docker (Recommended -- Zero Setup)

Ollama runs as a container alongside Firefly III. The model auto-downloads on first launch. Nothing to install, nothing to configure.

### How it works

The `docker-compose.yml` includes two Ollama services:

- **`ollama`** -- runs the Ollama server, models persisted in a Docker volume
- **`ollama-pull`** -- one-shot container that auto-pulls the model set in `BUDGET_LLM_MODEL`

### Just run

```bash
docker-compose up -d
```

First launch downloads the model (~5 GB for `llama3.1`). Subsequent starts are instant since the volume persists.

### Change the model

Edit `.env`:

```bash
BUDGET_LLM_MODEL=qwen2.5:14b    # better structured output, ~10 GB
# or
BUDGET_LLM_MODEL=mistral         # lighter, ~4 GB
# or
BUDGET_LLM_MODEL=llama3.1        # default, ~5 GB
```

Then restart:

```bash
docker-compose up -d
# ollama-pull will grab the new model automatically
```

### Verify

```bash
# Check Ollama is running inside Docker
docker exec firefly_iii_ollama ollama list

# Or from the host (port 11434 is exposed)
curl -s http://localhost:11434/api/tags | python3 -m json.tool
```

### .env settings (already configured by default)

```bash
BUDGET_LLM_BACKEND=ollama
BUDGET_LLM_MODEL=llama3.1
BUDGET_LLM_OLLAMA_URL=http://ollama:11434   # internal Docker network
BUDGET_CURRENCY=AED
```

The app container connects to `http://ollama:11434` over the Docker bridge network. Port 11434 is also exposed on the host for debugging.

### Use with the chat UI

Open Firefly III in your browser, click **Budget Planner** in the sidebar. Type your goals, hit Enter. That's it.

### Use with the CLI scripts

```bash
export FIREFLY_URL="http://localhost"
export FIREFLY_TOKEN="your-token"

python3 .cursor/skills/generate-budget-plan/scripts/extract_data.py \
  --months 3 --salary 25724 --currency AED \
  --output /tmp/data.json

# Point at the exposed Ollama port on the host
OLLAMA_URL=http://localhost:11434 \
python3 .cursor/skills/generate-budget-plan/scripts/generate_plan.py \
  /tmp/data.json --backend ollama --model llama3.1
```

---

## Option 2: Ollama on Host (Native GPU/Metal Acceleration)

If you want faster inference using Apple Silicon Metal or an NVIDIA GPU, install Ollama directly on the host instead of Docker. Docker on macOS does not pass through Metal/GPU.

### Install

```bash
# macOS (Homebrew)
brew install ollama

# Or download from https://ollama.com/download
```

### Pull a model and start

```bash
ollama pull llama3.1
ollama serve
# Runs on http://localhost:11434
```

### Configure Firefly III to use host Ollama

In `.env`, change the Ollama URL so the app container reaches the host:

```bash
BUDGET_LLM_OLLAMA_URL=http://host.docker.internal:11434
```

Or if running Firefly III without Docker:

```bash
BUDGET_LLM_OLLAMA_URL=http://localhost:11434
```

### Verify

```bash
curl -s http://localhost:11434/api/tags | python3 -m json.tool
```

---

## Option 3: Google Gemini Free Tier

Cloud-based, very capable (Gemini 2.0 Flash). Free tier: 15 requests/minute, ~1M tokens/day. No local resources needed.

### Get a free API key

1. Go to https://aistudio.google.com/app/apikey
2. Sign in with your Google account
3. Click "Create API key"
4. Copy the key

### Configure

In `.env`:

```bash
BUDGET_LLM_BACKEND=gemini
GEMINI_API_KEY=your-free-gemini-key
```

### Use with CLI scripts

```bash
export FIREFLY_URL="http://localhost"
export FIREFLY_TOKEN="your-token"
export GEMINI_API_KEY="your-free-gemini-key"

python3 .cursor/skills/generate-budget-plan/scripts/extract_data.py \
  --months 3 --salary 25724 --currency AED \
  --output /tmp/data.json

python3 .cursor/skills/generate-budget-plan/scripts/generate_plan.py \
  /tmp/data.json --backend gemini
```

### Free tier limits

- 15 requests per minute
- ~1,500 requests per day
- ~1M tokens per day
- More than enough for budget plans (you'll generate maybe 1-2 per month)

---

## Option 4: Groq Free Tier

Cloud-based, extremely fast inference on open-source models (Llama 3.3 70B).

### Get a free API key

1. Go to https://console.groq.com
2. Sign up (free)
3. Go to API Keys > Create API Key
4. Copy the key

### Configure

In `.env`:

```bash
BUDGET_LLM_BACKEND=groq
GROQ_API_KEY=your-free-groq-key
```

### Use with CLI scripts

```bash
export FIREFLY_URL="http://localhost"
export FIREFLY_TOKEN="your-token"
export GROQ_API_KEY="your-free-groq-key"

python3 .cursor/skills/generate-budget-plan/scripts/extract_data.py \
  --months 3 --salary 25724 --currency AED \
  --output /tmp/data.json

python3 .cursor/skills/generate-budget-plan/scripts/generate_plan.py \
  /tmp/data.json --backend groq
```

### Free tier limits

- 30 requests per minute
- 14,400 requests per day
- 6,000 tokens/minute for larger models
- Runs Llama 3.3 70B which produces excellent budget plans

---

## Comparison

| Feature | Docker (default) | Ollama on Host | Gemini Free | Groq Free |
|---------|-----------------|----------------|-------------|-----------|
| Cost | Free forever | Free forever | Free (with limits) | Free (with limits) |
| Setup | Zero (just `docker-compose up`) | `brew install ollama` | Get API key | Get API key |
| Internet needed | First launch only | First launch only | Always | Always |
| GPU/Metal accel. | No (Docker limitation) | Yes (Apple Silicon, NVIDIA) | N/A (cloud) | N/A (cloud) |
| Privacy | 100% local | 100% local | Data sent to Google | Data sent to Groq |
| Speed | Good (CPU) | Fast (GPU/Metal) | Fast | Very fast |
| Model quality | Good (8B-14B) | Good (8B-14B) | Excellent (Gemini Flash) | Excellent (70B Llama) |
| Rate limits | None | None | 15 RPM | 30 RPM |
| API key needed | No | No | Yes (free) | Yes (free) |

**Recommendation**: Use Docker (Option 1) -- it works out of the box with `docker-compose up`. Switch to host Ollama (Option 2) if you want Metal/GPU acceleration for faster generation. Use Gemini or Groq if your machine doesn't have enough RAM for local models (need 8 GB+ free).

---

## Getting Your Firefly III Personal Access Token

Only needed for the CLI scripts. The in-app chat UI uses your existing session.

1. Open Firefly III in your browser (http://localhost)
2. Click your profile icon (top right) > Profile
3. Scroll to "OAuth" section
4. Under "Personal Access Tokens", click "Create New Token"
5. Name it anything (e.g., "budget-generator")
6. Copy the token immediately (it won't be shown again)
7. Set it: `export FIREFLY_TOKEN="your-token-here"`

You can also add these to your shell profile (`~/.zshrc`):

```bash
export FIREFLY_URL="http://localhost"
export FIREFLY_TOKEN="your-token-here"
```
