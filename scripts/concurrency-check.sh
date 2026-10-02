#!/usr/bin/env sh
# Black-box proof of the two concurrency guarantees, against a RUNNING API (docker compose up).
#
#   1. Idempotency: N parallel requests sharing one Idempotency-Key execute the operation exactly once.
#   2. No overselling: M parallel write-offs against a stock of S units succeed exactly S times.
#
# Usage: scripts/concurrency-check.sh <api-key> [base-url]
#   api-key   from `php bin/console app:merchant:create "Name"` or `app:demo:seed`
#   base-url  default http://localhost:8080/api/v1
#
# Requires: curl, xargs with -P support (GNU/BSD), sed, sort, uniq.
set -eu

KEY="${1:?usage: $0 <api-key> [base-url]}"
BASE="${2:-http://localhost:8080/api/v1}"
RUN="$(date +%s)$$"
PARALLEL_DUPLICATES=30
PARALLEL_SALES=40
STOCK=10

export KEY BASE RUN

field() { sed -n "s/.*\"$1\":\"\\([^\"]*\\)\".*/\\1/p" | head -n 1; }
api() { method="$1"; path="$2"; shift 2; curl -s -X "$method" -H "X-API-Key: $KEY" -H 'Content-Type: application/json' "$@" "$BASE$path"; }

echo "== Setup"
WID="$(api POST /warehouses -d "{\"code\":\"CC-$RUN\",\"name\":\"Concurrency check\"}" | field id)"
PID="$(api POST /products -d "{\"sku\":\"CC-$RUN\",\"name\":\"Concurrency check\"}" | field id)"
[ -n "$WID" ] && [ -n "$PID" ] || { echo "Setup failed (is the API key valid and the API running?)"; exit 1; }
export WID PID
api PUT "/products/$PID/stock/$WID" -d "{\"quantity\":$STOCK}" >/dev/null
echo "warehouse=$WID product=$PID stock=$STOCK"

echo
echo "== 1. $PARALLEL_DUPLICATES parallel POSTs with ONE Idempotency-Key"
seq 1 "$PARALLEL_DUPLICATES" | xargs -P "$PARALLEL_DUPLICATES" -I{} \
    curl -s -o /dev/null -w '%{http_code}\n' -X POST \
    -H "X-API-Key: $KEY" -H "Idempotency-Key: dup-$RUN" -H 'Content-Type: application/json' \
    -d "{\"sku\":\"IDEM-$RUN\",\"name\":\"Idempotent\"}" "$BASE/products" | sort | uniq -c | sed 's/^/   status /'
CREATED="$(api GET "/products?q=IDEM-$RUN" | sed -n 's/.*"total":\([0-9]*\).*/\1/p')"
echo "   products created with that SKU: $CREATED (expected 1)"

echo
echo "== 2. $PARALLEL_SALES parallel write-offs of 1 unit from a stock of $STOCK"
seq 1 "$PARALLEL_SALES" | xargs -P "$PARALLEL_SALES" -I{} \
    curl -s -o /dev/null -w '%{http_code}\n' -X POST \
    -H "X-API-Key: $KEY" -H "Idempotency-Key: sale-$RUN-{}" -H 'Content-Type: application/json' \
    -d '{"delta":-1}' "$BASE/products/$PID/stock/$WID/adjustments" | sort | uniq -c | sed 's/^/   status /'
FINAL="$(api GET "/products/$PID/stock" | sed -n 's/.*"total":\([0-9-]*\).*/\1/p')"
echo "   final stock: $FINAL (expected 0, never negative)"

echo
if [ "$CREATED" = "1" ] && [ "$FINAL" = "0" ]; then
    echo "PASS: exactly-once execution and no overselling under concurrency."
else
    echo "FAIL: guarantees violated."
    exit 1
fi
