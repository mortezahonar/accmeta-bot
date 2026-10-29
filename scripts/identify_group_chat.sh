#!/usr/bin/env bash
set -euo pipefail

if [ -z "${ACCMETA_BOT_TOKEN:-}" ]; then
  echo "Missing ACCMETA_BOT_TOKEN; no Telegram request was made." >&2
  exit 2
fi

workdir=$(mktemp -d)
trap 'rm -rf "$workdir"' EXIT
api="https://api.telegram.org/bot${ACCMETA_BOT_TOKEN}"

status=$(curl -sS --max-time 25 -o "$workdir/webhook.json" -w '%{http_code}' "$api/getWebhookInfo" 2>/dev/null || true)
if [ "$status" != "200" ] || ! jq -e '.ok == true' "$workdir/webhook.json" >/dev/null 2>&1; then
  echo "Could not safely check Telegram webhook state; response details were suppressed." >&2
  exit 1
fi

webhook_url=$(jq -r '.result.url // ""' "$workdir/webhook.json")
if [ -n "$webhook_url" ]; then
  echo "A Telegram webhook is already configured; no polling or changes were made."
  exit 3
fi

status=$(curl -sS --max-time 25 -X POST -d 'timeout=0&limit=100' -o "$workdir/updates.json" -w '%{http_code}' "$api/getUpdates" 2>/dev/null || true)
if [ "$status" != "200" ] || ! jq -e '.ok == true and (.result | type == "array")' "$workdir/updates.json" >/dev/null 2>&1; then
  echo "Could not safely read pending Telegram updates; response details were suppressed." >&2
  exit 1
fi

count=$(jq '.result | length' "$workdir/updates.json")
if [ "$count" -eq 0 ]; then
  echo "No pending update found. Send /chatid in the target group, then run this check again."
  exit 4
fi
if [ "$count" -ne 1 ]; then
  echo "More than one update is pending; stopped to avoid consuming unrelated messages."
  exit 5
fi

if ! jq -e '
  .result[0].message.text? | test("^/chatid(@Accmeta_bot)?$"; "i")
' "$workdir/updates.json" >/dev/null 2>&1; then
  echo "The single pending update is not /chatid; nothing was processed or consumed."
  exit 6
fi

chat_type=$(jq -r '.result[0].message.chat.type // ""' "$workdir/updates.json")
if [ "$chat_type" != "group" ] && [ "$chat_type" != "supergroup" ]; then
  echo "The /chatid update did not come from a group; nothing was processed or consumed."
  exit 7
fi

chat_id=$(jq -r '.result[0].message.chat.id | tostring' "$workdir/updates.json")
update_id=$(jq -r '.result[0].update_id' "$workdir/updates.json")
if ! [[ "$chat_id" =~ ^-?[0-9]+$ ]] || ! [[ "$update_id" =~ ^[0-9]+$ ]]; then
  echo "Telegram returned an unexpected identifier format; values were suppressed."
  exit 1
fi

status=$(curl -sS --max-time 25 -X POST \
  --data-urlencode "chat_id=${chat_id}" \
  --data-urlencode "text=ACCMETA_CHAT_ID: ${chat_id}" \
  -o "$workdir/send.json" -w '%{http_code}' "$api/sendMessage" 2>/dev/null || true)
if [ "$status" != "200" ] || ! jq -e '.ok == true' "$workdir/send.json" >/dev/null 2>&1; then
  echo "Could not send the ID reply; response details were suppressed and the update was not acknowledged." >&2
  exit 1
fi

# Exactly one pending update was verified above, so acknowledge only that command.
next_offset=$((update_id + 1))
ack_status=$(curl -sS --max-time 25 -X POST \
  --data-urlencode "offset=${next_offset}" \
  --data-urlencode 'limit=1' \
  --data-urlencode 'timeout=0' \
  -o "$workdir/ack.json" -w '%{http_code}' "$api/getUpdates" 2>/dev/null || true)
if [ "$ack_status" = "200" ] && jq -e '.ok == true' "$workdir/ack.json" >/dev/null 2>&1; then
  echo "The numeric group ID was sent to the target group. No Telegram payload or token was printed."
else
  echo "The numeric group ID was sent, but Telegram did not confirm the diagnostic update; it may be delivered again later."
fi
