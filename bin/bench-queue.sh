#!/usr/bin/env bash
# Queue throughput on SQLite, MySQL 8.4 and Postgres 17 (Docker), 1 and 4 workers.
#   bin/bench-queue.sh [jobs]     DBS=pgsql bin/bench-queue.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP:-8.3}"
DBS="${DBS:-sqlite mysql pgsql}"
NET=pype-bench-net
IMAGE="pype-test-php:$PHP-2"   # built by bin/test-databases.sh

docker image inspect "$IMAGE" >/dev/null 2>&1 || { echo "Run bin/test-databases.sh once first (builds $IMAGE)."; exit 1; }
cleanup() { docker rm -f pype-bench-mysql pype-bench-pgsql >/dev/null 2>&1 || true; docker network rm $NET >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker network create $NET >/dev/null

for db in $DBS; do
    case $db in
        mysql)
            docker run -d --name pype-bench-mysql --network $NET -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=pype_test mysql:8.4 >/dev/null
            until docker exec pype-bench-mysql mysql -uroot -psecret -e 'SELECT 1' pype_test >/dev/null 2>&1; do sleep 1; done
            env=(-e PYPE_TEST_DB=mysql -e PYPE_TEST_DB_HOST=pype-bench-mysql) ;;
        pgsql)
            docker run -d --name pype-bench-pgsql --network $NET -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=pype_test postgres:17 >/dev/null
            until docker exec pype-bench-pgsql pg_isready -U postgres -d pype_test >/dev/null 2>&1; do sleep 1; done
            sleep 1
            env=(-e PYPE_TEST_DB=pgsql -e PYPE_TEST_DB_HOST=pype-bench-pgsql) ;;
        *)  env=(-e PYPE_TEST_DB=sqlite) ;;
    esac

    docker run --rm --network $NET "${env[@]}" -v "$ROOT:/app" -w /app "$IMAGE" php bin/bench-queue.php "$@"
done
