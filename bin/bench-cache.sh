#!/usr/bin/env bash
# Cache store micro-benchmark in Docker (PHP with opcache + APCu, as in production).
#   bin/bench-cache.sh [iterations]      PHP=8.4 bin/bench-cache.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP:-8.3}"
IMAGE="pype-test-php:$PHP"   # built by bin/test-databases.sh

docker image inspect "$IMAGE" >/dev/null 2>&1 || { echo "Run bin/test-databases.sh once first (builds $IMAGE)."; exit 1; }
docker run --rm -v "$ROOT:/app" -w /app "$IMAGE" php -d opcache.enable_cli=1 -d opcache.file_update_protection=0 -d opcache.jit=off bin/bench-cache.php "$@"
