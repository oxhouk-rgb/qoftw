#!/usr/bin/env bash
# run_integration_join.sh — прогон интеграционного HTTP-теста Этапа 1.
#
#   init.php?reset=1 → init.php (реестры) → join.php ×4 → poll.php →
#   rejoin → uid=5 (400) → чужой слот (409) → битые карточки (400) →
#   одиночный игрок + отключённый экран победы.
#
# Сервер: php -n -S с auto_prepend_file=fakeredis_stub.php (Redis-двойник в
# памяти одного процесса; FAKEREDIS_SHARED=1 включает shared-хранилище).
# Тест мутирует ТОЛЬКО фейковое хранилище — data/* и боевой Redis не трогаются.
#
# Запуск: bash tests/run_integration_join.sh
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${PORT:-8899}"
LOG="$(mktemp /tmp/integration_join_srv.XXXXXX.log)"

php -l "$ROOT/tests/integration_join.php" >/dev/null || { echo "Линт теста не прошёл"; exit 1; }

# Сервер: php -S с router-режимом самого теста (--serve): каждый запрос
# проксируется в реальный эндпоинт php/*.php. auto_prepend_file подключает
# Redis-двойник ДО кода эндпоинтов.
FAKEREDIS_SHARED=1 php -n -S "127.0.0.1:$PORT" \
    -d auto_prepend_file="$ROOT/tests/fakeredis_stub.php" \
    "$ROOT/tests/integration_join.php" --serve >"$LOG" 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null; rm -f "$LOG"' EXIT

# Ждём готовности сервера (до 15 с). Первый poll может держать long-poll до
# LONG_POLL_TIMEOUT — ждём именно ответа сервера, а не просто коннекта.
READY=0
for i in $(seq 1 30); do
    if curl -s --max-time 35 "http://127.0.0.1:$PORT/init?reset=1" | grep -q '"success"'; then READY=1; break; fi
    sleep 0.5
done
[ "$READY" = "1" ] || { echo "Сервер не поднялся за 15 с"; tail -20 "$LOG"; exit 1; }

HTTP_HOST="127.0.0.1:$PORT" php -n "$ROOT/tests/integration_join.php"
RC=$?

if [ $RC -ne 0 ]; then
    echo "--- лог сервера (последние 40 строк) ---"
    tail -40 "$LOG"
fi
exit $RC
