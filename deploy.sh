#!/usr/bin/env bash
# Deploy/start the InteLIS Insights stack with Docker + Compose.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

has_cmd() { command -v "$1" >/dev/null 2>&1; }
APP_PORT_VALUE=""

ensure_env_file() {
    if [ ! -f "$ROOT_DIR/.env" ]; then
        cp "$ROOT_DIR/.env.example" "$ROOT_DIR/.env"
        echo "Created .env from .env.example. Please edit it with your DB credentials and API keys, then re-run deploy.sh."
        exit 0
    fi
}

install_docker_linux() {
    if ! has_cmd apt-get; then
        echo "Docker is missing and this script only auto-installs on apt-based systems. Please install Docker manually."
        exit 1
    fi

    echo "Installing Docker and docker-compose-plugin via apt..."
    sudo apt-get update
    sudo apt-get install -y docker.io docker-compose-plugin
    sudo systemctl enable --now docker
}

ensure_docker() {
    if has_cmd docker; then
        return
    fi

    case "$(uname -s)" in
        Linux) install_docker_linux ;;
        Darwin)
            echo "Docker is not installed. Install Docker Desktop for macOS (https://docs.docker.com/desktop/install/mac-install/) and re-run."
            exit 1
            ;;
        *)
            echo "Unsupported OS for auto-install. Please install Docker manually."
            exit 1
            ;;
    esac
}

compose_bin() {
    if docker compose version >/dev/null 2>&1; then
        echo "docker compose"
    elif docker-compose version >/dev/null 2>&1; then
        echo "docker-compose"
    else
        echo ""
    fi
}

resolve_app_port() {
    if [ -n "${APP_PORT:-}" ]; then
        APP_PORT_VALUE="$APP_PORT"
        return
    fi

    if [ -f "$ROOT_DIR/.env" ]; then
        local env_port
        env_port="$(grep -m1 '^APP_PORT=' "$ROOT_DIR/.env" | cut -d= -f2- | tr -d '\"' | tr -d '\r' || true)"
        APP_PORT_VALUE="${env_port:-8080}"
    else
        APP_PORT_VALUE="8080"
    fi
}

main() {
    ensure_docker

    COMPOSE_CMD="$(compose_bin)"
    if [ -z "$COMPOSE_CMD" ]; then
        echo "docker compose is not available. Install docker-compose-plugin (Linux) or Docker Desktop (macOS/Windows)."
        exit 1
    fi

    ensure_env_file
    resolve_app_port

    echo "Using Compose command: $COMPOSE_CMD"
    echo "Building images (app, rag-api)..."
    $COMPOSE_CMD -f "$ROOT_DIR/docker-compose.yml" build app rag-api

    echo "Pulling latest qdrant image (if needed)..."
    $COMPOSE_CMD -f "$ROOT_DIR/docker-compose.yml" pull qdrant || true

    echo "Starting stack..."
    $COMPOSE_CMD -f "$ROOT_DIR/docker-compose.yml" up -d

    echo "Stack is up. Current status:"
    $COMPOSE_CMD -f "$ROOT_DIR/docker-compose.yml" ps

    cat <<NOTE

Next steps:
- Ensure your database is reachable using the credentials in .env.
- Generate schema (inside the app container) after the DB is reachable:
    $COMPOSE_CMD -f "$ROOT_DIR/docker-compose.yml" exec app php bin/export-schema.php
- UI: http://localhost:${APP_PORT_VALUE}/chat
- API: POST http://localhost:${APP_PORT_VALUE}/ask

NOTE
}

main "$@"
