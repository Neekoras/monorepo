#!/usr/bin/env bash
# Benchmark the cache codecs, alone and through the Redis adapters.
#
# Needs Redis. Brings up the package's own compose service when Docker is
# available and it is not already reachable, and skips rather than fails when it
# cannot: the Benchmark workflow is informational, and a benchmark that reddens a
# PR because a runner has no Docker is worse than no benchmark.
set -eu

cd "$(dirname "$0")/../.." # packages/cache

REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-16381}"
ITERATIONS="${ITERATIONS:-2000}"
REPEAT="${REPEAT:-3}"
CORES=$(nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo '?')
export REDIS_HOST REDIS_PORT ITERATIONS REPEAT

php -r 'exit(extension_loaded("igbinary") ? 0 : 1);' || { echo "ext-igbinary is required" >&2; exit 1; }

reachable() { # host port
    php -r '$s=@fsockopen($argv[1],(int)$argv[2],$e,$m,1.0); exit($s===false?1:0);' "$1" "$2"
}

# Its own Compose project, never the default one, so tearing down only removes
# what this script started and never a stack the developer already had up.
PROJECT="utopia-cache-bench-$$"
COMPOSE_STARTED=
if ! reachable "$REDIS_HOST" "$REDIS_PORT"; then
    if command -v docker > /dev/null 2>&1 && docker info > /dev/null 2>&1; then
        echo "starting compose service (redis) in project $PROJECT" >&2
        docker compose -p "$PROJECT" up -d --wait redis >&2
        COMPOSE_STARTED=1
    else
        echo "cache bench skipped: needs Redis on ${REDIS_HOST}:${REDIS_PORT}, and Docker is unavailable to start it" >&2
        exit 0
    fi
fi
# shellcheck disable=SC2064
[ -n "$COMPOSE_STARTED" ] && trap "docker compose -p '$PROJECT' down -v --remove-orphans > /dev/null 2>&1 || true" EXIT

rows=$(php tests/bench/codec.php | awk '{
    printf "| %s | %s | %s | %s | %s | %s |\n", $1, $2, $3, $4, $5, $6
}')

table="| adapter | codec | payload | bytes | save ops/s | load ops/s |
|---|---|---|---|---|---|
${rows}"

section="### cache — codec cost alone and through the Redis adapters (${CORES} cores, ${ITERATIONS} ops, median of ${REPEAT})

${table}

_Rows with adapter \`none\` time encode (save column) and decode (load column) with no adapter in front. \`small\` is one document, \`large\` a page of 50._"

echo
echo "$table"

# GITHUB_STEP_SUMMARY: the run's own job summary.
# BENCH_REPORT: shared file a bench script appends its section to, so the Benchmark
# workflow can collect every package into one place.
[ -n "${GITHUB_STEP_SUMMARY:-}" ] && printf '%s\n\n' "$section" >> "$GITHUB_STEP_SUMMARY"
[ -n "${BENCH_REPORT:-}" ] && printf '%s\n\n' "$section" >> "$BENCH_REPORT"

exit 0
