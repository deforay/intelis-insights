# InteLIS Insights

A service that converts natural language queries into SQL for InteLIS database using LLM providers.

## Setup

1. Clone the repository
2. Install dependencies:
   ```bash
   composer install
   ```
3. Configure the application:
   ```bash
   cp config/app.dist.php config/app.php
   cp config/db.dist.php config/db.php
   ```
4. Edit `config/app.php` and `config/db.php` with your database credentials and LLM provider settings

## Supported LLM Providers

- OpenAI (GPT models)
- Anthropic (Claude models)  
- Ollama (local models)

## Usage

The service converts queries like "How many VL tests in the last 6 months?" into appropriate SQL statements while enforcing privacy rules and business logic.