#!/usr/bin/env bash
#
# A signed API call, in nothing but curl and openssl.
#
# Useful for trying the gateway by hand, and as a reference if you are
# integrating from a language that has no client here yet — the four header
# lines below are the entire authentication scheme.
#
#   ./pay.sh create 150000 ORDER-1     # create a payment, print the checkout URL
#   ./pay.sh status <payment-id>       # ask whether it was paid
#   ./pay.sh gateways                  # list the gateways this site may use
#
# Configure once, either by editing these three lines or by exporting them:
#
#   export GATEWAY_URL=http://localhost:8080
#   export GATEWAY_KEY=pk_...
#   export GATEWAY_SECRET=sk_...
#
set -euo pipefail

BASE="${GATEWAY_URL:-http://localhost:8080}"
KEY="${GATEWAY_KEY:-pk_replace_me}"
SECRET="${GATEWAY_SECRET:-sk_replace_me}"

# ---------------------------------------------------------------------------
# The signature. This is the whole of it.
#
#   METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256(BODY)
#
# signed with HMAC-SHA256 under your secret. The secret itself is never sent.
# ---------------------------------------------------------------------------
call() {
    local method="$1" endpoint="$2" body="${3:-}"

    local ts nonce body_hash canonical signature
    ts="$(date +%s)"
    nonce="$(openssl rand -hex 16)"
    body_hash="$(printf '%s' "$body" | openssl dgst -sha256 | awk '{print $NF}')"
    canonical="$(printf '%s\n%s\n%s\n%s\n%s' "$method" "$endpoint" "$ts" "$nonce" "$body_hash")"
    signature="$(printf '%s' "$canonical" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')"

    curl -sS -X "$method" "${BASE}${endpoint}" \
        -H 'Content-Type: application/json' \
        -H 'Accept: application/json' \
        -H "X-Gateway-Key: ${KEY}" \
        -H "X-Gateway-Timestamp: ${ts}" \
        -H "X-Gateway-Nonce: ${nonce}" \
        -H "X-Gateway-Signature: ${signature}" \
        ${body:+--data "$body"}
}

pretty() {
    if command -v jq >/dev/null 2>&1; then jq .; else cat; echo; fi
}

case "${1:-}" in
    create)
        amount="${2:-150000}"
        order="${3:-ORDER-$(date +%s)}"

        # `amount` is an integer in the currency's minor unit. Toman and Rial
        # have no decimals, so 150000 IRT is exactly 150,000 Toman.
        body="$(printf '{"amount":%s,"currency":"IRT","order_id":"%s","callback_url":"https://example.com/return","description":"Manual test","idempotency_key":"order-%s"}' \
            "$amount" "$order" "$order")"

        call POST /api/v1/payments "$body" | pretty
        ;;

    status)
        [ $# -ge 2 ] || { echo "Usage: $0 status <payment-id>" >&2; exit 1; }
        call GET "/api/v1/payments/$2" | pretty
        ;;

    verify)
        [ $# -ge 2 ] || { echo "Usage: $0 verify <payment-id>" >&2; exit 1; }
        call POST "/api/v1/payments/$2/verify" | pretty
        ;;

    gateways)
        call GET /api/v1/gateways | pretty
        ;;

    *)
        sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'
        exit 1
        ;;
esac
