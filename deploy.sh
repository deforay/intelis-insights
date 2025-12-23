#!/usr/bin/env bash
# Deploy/start the InteLIS Insights stack with Docker + Compose.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

has_cmd() { command -v "$1" >/dev/null 2>&1; }
APP_PORT_VALUE=""
ENABLE_TRAEFIK_VALUE=""
APP_DOMAIN_VALUE=""
TRAEFIK_EMAIL_VALUE=""

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

get_env_val() {
    # Usage: get_env_val KEY DEFAULT
    local key="$1"
    local default_val="$2"
    local from_env="${!key:-}"
    if [ -n "$from_env" ]; then
        echo "$from_env"
        return
    fi
    if [ -f "$ROOT_DIR/.env" ]; then
        local line
        line="$(grep -m1 "^${key}=" "$ROOT_DIR/.env" | cut -d= -f2- | tr -d '\"' | tr -d '\r' || true)"
        if [ -n "$line" ]; then
            echo "$line"
            return
        fi
    fi
    echo "$default_val"
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

resolve_proxy_settings() {
    ENABLE_TRAEFIK_VALUE="$(get_env_val ENABLE_TRAEFIK "false")"
    APP_DOMAIN_VALUE="$(get_env_val APP_DOMAIN "")"
    TRAEFIK_EMAIL_VALUE="$(get_env_val TRAEFIK_ACME_EMAIL "")"
}

prepare_traefik_storage() {
    local dir="$ROOT_DIR/traefik_data"
    local file="$dir/acme.json"
    mkdir -p "$dir"
    if [ ! -f "$file" ]; then
        touch "$file"
        chmod 600 "$file"
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
    resolve_proxy_settings

    PROFILE_ARGS=()
    traefik_flag="$(printf '%s' "$ENABLE_TRAEFIK_VALUE" | tr '[:upper:]' '[:lower:]')"
    if [ "$traefik_flag" = "true" ] || [ "$traefik_flag" = "1" ] || [ "$traefik_flag" = "yes" ] || [ "$traefik_flag" = "on" ]; then
        if [ -z "$APP_DOMAIN_VALUE" ] || [ "$APP_DOMAIN_VALUE" = "app.example.com" ]; then
            echo "ENABLE_TRAEFIK is true but APP_DOMAIN is not set to a real domain. Update .env and re-run."
            exit 1
        fi
        if [ -z "$TRAEFIK_EMAIL_VALUE" ] || [ "$TRAEFIK_EMAIL_VALUE" = "admin@example.com" ]; then
            echo "ENABLE_TRAEFIK is true but TRAEFIK_ACME_EMAIL is not set. Update .env and re-run."
            exit 1
        fi
        prepare_traefik_storage
        PROFILE_ARGS=(--profile proxy)
        echo "Traefik/HTTPS enabled for ${APP_DOMAIN_VALUE} (ACME email: ${TRAEFIK_EMAIL_VALUE})."
    else
        echo "Traefik/HTTPS disabled; serving on http://localhost:${APP_PORT_VALUE}."
    fi

    echo "Using Compose command: $COMPOSE_CMD"
    echo "Building images (app, rag-api)..."
    $COMPOSE_CMD -f "$ROOT_DIR/docker-compose.yml" "${PROFILE_ARGS[@]}" build app rag-api

    echo "Pulling latest qdrant image (if needed)..."
    $COMPOSE_CMD -f "$ROOT_DIR/docker-compose.yml" "${PROFILE_ARGS[@]}" pull qdrant || true

    echo "Starting stack..."
    $COMPOSE_CMD -f "$ROOT_DIR/docker-compose.yml" "${PROFILE_ARGS[@]}" up -d

    echo "Stack is up. Current status:"
    $COMPOSE_CMD -f "$ROOT_DIR/docker-compose.yml" "${PROFILE_ARGS[@]}" ps

    cat <<NOTE

Next steps:
- Ensure your database is reachable using the credentials in .env.
- Generate schema (inside the app container) after the DB is reachable:
    $COMPOSE_CMD -f "$ROOT_DIR/docker-compose.yml" exec app php bin/export-schema.php
- UI: http://localhost:${APP_PORT_VALUE}/chat (or https://${APP_DOMAIN_VALUE:-app.example.com}/chat if Traefik/HTTPS is enabled)
- API: POST http://localhost:${APP_PORT_VALUE}/ask (or https://${APP_DOMAIN_VALUE:-app.example.com}/ask if Traefik/HTTPS is enabled)

NOTE
}

main "$@"
