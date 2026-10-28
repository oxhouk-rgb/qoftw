#!/usr/bin/env bash
# hang_probe.sh — регрессия на «игру, ломающуюся после первого действия» (HTTP 503).
#
# Сценарий бага: action.php применяет действие через CAS (world_save_cas →
# saveBattleState с expectedVersion). Из-за рассинхрона ДВУХ счётчиков версии
# (REDIS_VERSION — monotonic-каунтер long-poll, инкрементируется в legacy-пути
# poll.php/init; REDIS_STATE_VERSION — CAS-счётчик, обновлялся только CAS-путём)
# каждая вторая CAS-запись ложно считалась гонкой → 3 неудачные попытки →
# стабильный 503. Исправление: единый счётчик версии для обоих путей записи.
#
# Проба прогоняет НЕСКОЛЬКО последовательных действий при ФОНОВОМ long-poll
# (параллельные тики, как в реальной игре) и требует success:true от каждого.
#
# Запуск: bash tests/hang_probe.sh [base_url]
#   base_url по умолчанию http://127.0.0.1:8899 (php -S 127.0.0.1:8899 -t php)
set -u

BASE="${1:-http://127.0.0.1:8899}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FAIL=0

say() { echo "$@"; }
check() { # name cond_ok
    if [ "$2" = "1" ]; then say "OK   $1"; else say "FAIL $1"; FAIL=1; fi
}

# Сервер жив?
if ! curl -s -o /dev/null --max-time 3 "$BASE/poll.php?v=0"; then
    say "SKIP сервер недоступен на $BASE (запустите: php -S 127.0.0.1:8899 -t php)"
    exit 0
fi

# Чистый мир + детерминированные стартовые координаты PLAYER1 (уязвимость к
# фоновому NPC-бою делает исход боя непредсказуемым для пробы).
curl -s -X POST "$BASE/init.php" > /dev/null
redis-cli set battle:players "$(redis-cli get battle:players | python3 -c '
import sys, json
p = json.load(sys.stdin)
p["PLAYER1"].update({"CUR_POSITION_XY": "1,1", "ACTIONS": 99, "STATUS": "IDLE", "HP": 100})
p.pop("PLAYER2", None); p.pop("PLAYER3", None); p.pop("PLAYER4", None)
print(json.dumps(p))')" > /dev/null
redis-cli del battle:npcs battle:action_acks > /dev/null
redis-cli incr battle:version > /dev/null

# Фоновый long-poll: бесконечная лента GET-запросов → параллельные тики мира
# (движение NPC, ходы), ровно та конкурентная обстановка, в которой жил баг.
PIDS=()
for i in 1 2 3; do
    ( while true; do curl -s --max-time 30 "$BASE/poll.php?v=0" > /dev/null; done ) &
    PIDS+=($!)
done
cleanup() { for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done; wait 2>/dev/null; }
trap cleanup EXIT
sleep 1

post_action() { # x y label -> prints HTTP code and body
    local id="probe_$RANDOM$RANDOM"
    curl -s -o /tmp/hang_probe_body -w '%{http_code}' -X POST "$BASE/action.php" \
        -H 'Content-Type: application/json' \
        -d "{\"action\":\"move\",\"player\":\"PLAYER1\",\"x\":$1,\"y\":$2,\"action_id\":\"$id\"}"
}

# ДВА последовательных действия: именно со второго начинался стабильный 503.
# Между ходами ждём завершения анимации движения (STATUS MOVING→IDLE ловится
# штатным тиком фонового poll), иначе второе действие отклоняется правилами
# («нельзя действовать во время движения») — это штатный 400, а не регрессия.
wait_idle() { # player timeout_s
    local end=$((SECONDS + ${2:-20}))
    while [ $SECONDS -lt $end ]; do
        ST=$(php -r '
require "'"$ROOT"'/php/engine/bootstrap.php";
$s = world_load();
echo $s["players"]["'"$1"'"]["STATUS"] ?? "?";' 2>/dev/null)
        [ "$ST" = "IDLE" ] && return 0
        sleep 0.5
    done
    return 1
}

COORDS=("2,1" "3,1")
ALL_OK=1
for c in "${COORDS[@]}"; do
    wait_idle PLAYER1 20
    X=${c%,*}; Y=${c#*,}
    CODE=$(post_action "$X" "$Y")
    BODY=$(cat /tmp/hang_probe_body)
    SUCCESS=$(echo "$BODY" | python3 -c 'import sys,json
try: print(1 if json.load(sys.stdin).get("success") else 0)
except Exception: print(0)')
    if [ "$CODE" != "200" ] || [ "$SUCCESS" != "1" ]; then ALL_OK=0; fi
    say "     move → ($X,$Y): HTTP $CODE $(echo "$BODY" | head -c 120)"
done
check "последовательные действия под фоновым long-poll не дают 503" "$ALL_OK"

# Мутация реально в мире (не только ack): после второго хода PLAYER1 достигнет
# целевой клетки маршрута (путь по клеткам: (2,1) → (3,1)).
wait_idle PLAYER1 20
POS=$(php -r '
require "'"$ROOT"'/php/engine/bootstrap.php";
$s = world_load();
echo $s["players"]["PLAYER1"]["CUR_POSITION_XY"] ?? "?";')
check "финальная позиция PLAYER1 применена в мире (ожидалось ${COORDS[-1]}, получено $POS)" \
      "$([ "$POS" = "${COORDS[-1]}" ] && echo 1 || echo 0)"

# Идемпотентность: ретрай повторного запроса НЕ должен порождать новую ошибку
CODE_DUP=$(curl -s -o /tmp/hang_probe_body -w '%{http_code}' -X POST "$BASE/action.php" \
    -H 'Content-Type: application/json' \
    -d '{"action":"move","player":"PLAYER1","x":9,"y":9,"action_id":"dup_probe"}')
CODE_DUP2=$(curl -s -o /tmp/hang_probe_body2 -w '%{http_code}' -X POST "$BASE/action.php" \
    -H 'Content-Type: application/json' \
    -d '{"action":"move","player":"PLAYER1","x":9,"y":9,"action_id":"dup_probe"}')
OK_DUP=0
[ "$CODE_DUP" = "200" ] && [ "$CODE_DUP2" = "200" ] && OK_DUP=1
check "ретрай с тем же action_id идемпотентен (HTTP $CODE_DUP/$CODE_DUP2)" "$OK_DUP"

exit $FAIL
