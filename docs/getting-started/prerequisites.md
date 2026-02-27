# Prerequisites

Before you begin, make sure you have the tools below. The Docker path is simplest — it only needs two things.

## Docker Setup (Recommended)

| Tool | Why | Install |
|------|-----|---------|
| **Git** | Clone the repository | [git-scm.com](https://git-scm.com/) |
| **Docker Desktop** | Runs all services in containers | [docker.com/get-docker](https://docs.docker.com/get-docker/) |

That's it. Docker handles PHP, MySQL, Qdrant, the RAG API, and the LLM sidecar for you.

## Manual Setup (Without Docker)

If you prefer running services natively, you'll need more:

| Tool | Version | Why | Install |
|------|---------|-----|---------|
| **Git** | Any | Clone the repository | [git-scm.com](https://git-scm.com/) |
| **PHP** | 8.4+ | Runs the application | [php.net](https://www.php.net/downloads) |
| **Composer** | 2.x | PHP dependency manager | [getcomposer.org](https://getcomposer.org/) |
| **MySQL** | 8.0+ | Database | [dev.mysql.com](https://dev.mysql.com/downloads/) |
| **Bun** | 1.x | Runs the LLM sidecar | [bun.sh](https://bun.sh/) |
| **Docker** | Any | Still needed for Qdrant + RAG API | [docker.com](https://docs.docker.com/get-docker/) |

??? note "PHP extensions required"
    Make sure these PHP extensions are enabled (most are on by default):

    - `pdo_mysql`
    - `mbstring`
    - `json`
    - `curl`

??? note "Installing Bun"
    Bun is a fast JavaScript runtime (like Node.js but faster). Install it with:

    ```bash
    curl -fsSL https://bun.sh/install | bash
    source ~/.bashrc  # or ~/.zshrc on macOS
    bun --version     # verify
    ```

## LLM API Key

You need **at least one** API key from an LLM provider for the chat features to work. Get one from any of:

| Provider | Get a key | Env variable |
|----------|-----------|-------------|
| Anthropic (Claude) | [console.anthropic.com](https://console.anthropic.com/) | `ANTHROPIC_API_KEY` |
| OpenAI | [platform.openai.com](https://platform.openai.com/) | `OPENAI_API_KEY` |
| DeepSeek | [platform.deepseek.com](https://platform.deepseek.com/) | `DEEPSEEK_API_KEY` |
| Google (Gemini) | [aistudio.google.com](https://aistudio.google.com/) | `GOOGLE_GENERATIVE_AI_API_KEY` |
| Groq | [console.groq.com](https://console.groq.com/) | `GROQ_API_KEY` |

!!! tip "No API key yet?"
    The dashboard and reports features work without an API key. Only the conversational chat (which uses an LLM to generate SQL) requires one. You can set up the system first and add a key later.

## InteLIS Database (Optional at First)

The InteLIS database is the laboratory data that the LLM queries against. You can set up Intelis Insights without it and add it later — see [Connecting InteLIS Data](../guides/connecting-intelis-data.md).

**Without InteLIS data:**

- Dashboard loads but shows empty charts
- Chat UI loads but the AI cannot generate SQL queries
- Reports can be created but won't have data to query

**With InteLIS data:**

- Full dashboard with national indicators
- Chat can answer questions about lab data
- Reports produce real results

## Next Step

Ready? Head to the setup guide for your preferred approach:

- [Docker Setup](docker-setup.md) (recommended)
- [Manual Setup](manual-setup.md)
