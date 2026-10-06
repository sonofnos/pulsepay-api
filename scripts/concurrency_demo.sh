#!/usr/bin/env bash
# Fires N concurrent transfer requests, each trying to spend the *entire*
# balance of one wallet to a different recipient, with distinct Idempotency
# keys (so this is N real competing operations, not idempotent replays of one).
# If LedgerService's row-level locking is doing its job, exactly one request
# succeeds and the wallet never goes negative — regardless of how the OS
# schedules the concurrent connections.
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8123}"
N="${1:-10}"
STARTING_BALANCE=100000

echo "== PulsePay ledger concurrency demo: $N concurrent spends of a $STARTING_BALANCE-unit wallet =="

SPENDER_EMAIL="spender-$(date +%s)@example.com"
curl -s -X POST "$BASE_URL/api/auth/register" -H 'Content-Type: application/json' \
  -d "{\"name\":\"Spender\",\"email\":\"$SPENDER_EMAIL\",\"password\":\"password123\"}" > /tmp/spender.json
SPENDER_TOKEN=$(python3 -c "import json;print(json.load(open('/tmp/spender.json'))['token'])")
SPENDER_ID=$(python3 -c "import json;print(json.load(open('/tmp/spender.json'))['user']['id'])")

for i in $(seq 1 "$N"); do
  RECIPIENT_EMAIL="recipient-$i-$(date +%s)@example.com"
  curl -s -X POST "$BASE_URL/api/auth/register" -H 'Content-Type: application/json' \
    -d "{\"name\":\"R$i\",\"email\":\"$RECIPIENT_EMAIL\",\"password\":\"password123\"}" > /dev/null
  echo "$RECIPIENT_EMAIL" >> /tmp/recipients.txt
done

php artisan tinker --execute="App\Models\Wallet::where('user_id', $SPENDER_ID)->where('currency','NGN')->update(['balance' => $STARTING_BALANCE]);" > /dev/null

echo "Firing $N concurrent transfer requests for the full balance each..."
i=0
while read -r RECIPIENT_EMAIL; do
  i=$((i+1))
  curl -s -o "/tmp/result_$i.json" -w "%{http_code}" -X POST "$BASE_URL/api/wallets/transfer" \
    -H "Authorization: Bearer $SPENDER_TOKEN" -H 'Content-Type: application/json' \
    -H "Idempotency-Key: concurrency-demo-$i" \
    -d "{\"recipient_email\":\"$RECIPIENT_EMAIL\",\"currency\":\"NGN\",\"amount\":$STARTING_BALANCE}" \
    > "/tmp/status_$i.txt" &
done < /tmp/recipients.txt
wait

SUCCESS_COUNT=0
for f in /tmp/status_*.txt; do
  CODE=$(cat "$f")
  [ "$CODE" = "200" ] && SUCCESS_COUNT=$((SUCCESS_COUNT+1))
done

FINAL_BALANCE=$(curl -s "$BASE_URL/api/wallets" -H "Authorization: Bearer $SPENDER_TOKEN" \
  | python3 -c "import json,sys; w=[x for x in json.load(sys.stdin) if x['currency']=='NGN'][0]; print(w['balance'])")

echo "Successful transfers: $SUCCESS_COUNT / $N (expected: 1)"
echo "Final spender NGN balance: $FINAL_BALANCE (expected: 0, never negative)"

rm -f /tmp/recipients.txt /tmp/result_*.json /tmp/status_*.txt /tmp/spender.json

if [ "$SUCCESS_COUNT" -eq 1 ] && [ "$FINAL_BALANCE" -eq 0 ]; then
  echo "PASS: exactly one transfer succeeded, wallet never overdrawn."
  exit 0
else
  echo "FAIL: locking did not prevent a race condition."
  exit 1
fi
