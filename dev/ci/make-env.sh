#!/usr/bin/env bash
# Creates .env from .env.example with random passwords (CI, or a fresh checkout).
set -euo pipefail
cd "$(dirname "$0")/../.."

[ -e .env ] && { echo ".env exists, leaving it alone"; exit 0; }

while IFS= read -r line; do
    case "$line" in
        *=change-me) echo "${line%%=*}=$(openssl rand -hex 16)" ;;
        *) echo "$line" ;;
    esac
done < .env.example > .env

echo "created .env"
