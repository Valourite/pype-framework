#!/usr/bin/env bash
# Run the test suite against SQLite, MySQL 8.4 and Postgres 17 (all in Docker).
#
#   bin/test-databases.sh                 # all three
#   DBS="pgsql" bin/test-databases.sh     # just one
#   PHP=8.2 bin/test-databases.sh         # another PHP version
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP:-8.3}"
DBS="${DBS:-sqlite mysql pgsql}"
NET=pype-test-net
IMAGE="pype-test-php:$PHP"

cleanup() { docker rm -f pype-test-mysql pype-test-pgsql >/dev/null 2>&1 || true; docker network rm $NET >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker network create $NET >/dev/null

# PHP with every PDO driver plus APCu (cached after the first run).
if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    echo "▸ Building $IMAGE"
    docker build -q -t "$IMAGE" - >/dev/null <<DOCKERFILE
FROM php:$PHP-cli
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo_mysql pdo_pgsql \
 && pecl install apcu && docker-php-ext-enable apcu && echo 'apc.enable_cli=1' > /usr/local/etc/php/conf.d/apcu-cli.ini
DOCKERFILE
fi

for db in $DBS; do
    case $db in
        mysql)
            docker run -d --name pype-test-mysql --network $NET -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=pype_test mysql:8.4 >/dev/null
            until docker exec pype-test-mysql mysql -uroot -psecret -e 'SELECT 1' pype_test >/dev/null 2>&1; do sleep 1; done
            env=(-e PYPE_TEST_DB=mysql -e PYPE_TEST_DB_HOST=pype-test-mysql) ;;
        pgsql)
            docker run -d --name pype-test-pgsql --network $NET -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=pype_test postgres:17 >/dev/null
            until docker exec pype-test-pgsql pg_isready -U postgres -d pype_test >/dev/null 2>&1; do sleep 1; done
            sleep 1
            env=(-e PYPE_TEST_DB=pgsql -e PYPE_TEST_DB_HOST=pype-test-pgsql) ;;
        *)  env=(-e PYPE_TEST_DB=sqlite) ;;
    esac

    echo "▸ PHP $PHP / $db"
    docker run --rm --network $NET "${env[@]}" -v "$ROOT:/app" -w /app "$IMAGE" vendor/bin/phpunit --no-progress
done
