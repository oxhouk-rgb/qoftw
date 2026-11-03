<?php
/**
 * engine/snapshot.php — Сборщик снапшота мира + один виток тика.
 *
 * Вынесено из удалённого poll.php (переход на Centrifugo, ноябрь 2026):
 *   • build_snapshot_response() — плоский контракт снапшота (тот же, что
 *     раньше отдавал long-poll; клиентский render(data) не изменился);
 *   • world_tick_once() — ОДИН виток серверного тика (load → guard turn_time
 *     → turn_tick → CAS-save). Драйвер тика — tools/ticker.php (демон),
 *     он же публикует снапшоты в Centrifugo (engine/publish.php).
 *
 * Контракт снапшота (плоский, без обёртки 'data' — сверено с js/net.js/render.js):
 *   version, room, room_id, grid?, objects, traps, npcs, players, projectiles, log,
 *   turn_time, turn_duration, time_to_next_turn, victory, rules, server_now
 *   (room/objects/traps/npcs — комната запросившего игрока;
 *    players[].MOVE_RANGE — эффективная дальность хода со SWIFT/SLOWED)
 */

if (!function_exists('world_tick_once')) {
    /**
     * Один виток тика движка (бывший цикл poll.php, без HTTP-слоя).
     *
     * ★ turn_time = серверный дедлайн хода. Значение обязан задать сборщик
     * мира (engine/world_reset.php пишет его всегда). Здесь — только аварийная
     * страховка: если дедлайна нет вообще (null/''), фиксируем текущее время
     * ОДИН раз.
     * ⚠️ НЕ трогать значение 0: turn_check_advance по достижении дедлайна
     * обнуляет turn_time, а следующий ход выставляет заново; прежняя проверка
     * «<= 0» воспринимала это штатное обнуление как отсутствие таймера и
     * каждый тик ставила time()+save → incr версии → вечный шторм.
     *
     * ★ Запись тика — только CAS по версии загруженного снапшота: параллельный
     * процесс (действие/чужая запись) не затирается. При гонке тик НЕ ребейзим
     * намеренно: следующий виток повторится на свежем world_load().
     *
     * @return array [$state|null, $version, $changed] — null в $state означает
     *         «CAS-гонка, виток не применён» (вызывающий повторит цикл).
     */
    function world_tick_once() {
        $state = world_load();
        $base_version = (int)($state['version'] ?? 0);

        if (($state['turn_time'] ?? null) === null || $state['turn_time'] === '') {
            $state['turn_time'] = time();
            [$tick_written, $state] = world_save_cas($state, $base_version);
            if (!$tick_written) return [null, world_version(), false];
            $base_version = (int)($state['version'] ?? $base_version);
        }

        // Серверный тик движка (включая ход мира turn_check_advance — он
        // внутри turn_tick; повторный вызов отсюда устроил бы двойной advance).
        $changed_by_tick = turn_tick($state);
        if ($changed_by_tick) {
            [$tick_written, $state] = world_save_cas($state, $base_version);
            if (!$tick_written) return [null, world_version(), false];
        }

        return [$state, world_version(), (bool)$changed_by_tick];
    }
}

if (!function_exists('build_snapshot_response')) {
    /**
     * Сборка flat-снапшота (контракт клиента js/render.js render(data)).
     *
     * ★ Мультикомнатность: карта в ответе — комната ЗАПРОСИВШЕГО игрока,
     * а не единая state['room']. Сущности (objects/traps/npcs) фильтруются по
     * ROOM_ID той же комнаты; игроки остаются списком целиком — клиент сам
     * рисует только тех, чей ROOM_ID совпадает с текущей комнатой.
     * Если игрок неизвестен — legacy-карта state['room'].
     */
    function build_snapshot_response($state, $version, $player_id = null) {
        $now = time();

        // Комната запросившего игрока + её карта (лениво из state['rooms']/data)
        $cur_room_id = null;
        if ($player_id !== null && isset($state['players'][$player_id])) {
            $cur_room_id = $state['players'][$player_id]['ROOM_ID'] ?? null;
        }
        $room = null;
        if ($cur_room_id !== null && $cur_room_id !== '') {
            $room = $state['rooms'][$cur_room_id] ?? room_get($state, $cur_room_id);
        }
        if ($room === null) {
            $room = $state['room'] ?? [];
            $cur_room_id = $room['room_id'] ?? null;
        }

        // Эффективная дальность хода для подсветки движения на клиенте
        // (SWIFT/SLOWED уже учтены шиной — клиент НЕ дублирует формулу)
        $players_out = [];
        foreach ($state['players'] as $pid => $p) {
            if (!empty($p)) { // страховка от «дырявых» записей
                $p['MOVE_RANGE'] = movement_effective_range($state, $pid);
            }
            // ★ Гарантируем ROOM_ID в контракте: боец обязан иметь комнату даже
            // если снимок бойцов не задал поле (тогда — комната legacy-карты).
            // Без поля клиент считает такого бойца «межкомнатным» и рисует его
            // сразу во всех комнатах поверх чужих стен (баг дублирования).
            if (($p['ROOM_ID'] ?? '') === '' && $cur_room_id !== null && $cur_room_id !== '') {
                $p['ROOM_ID'] = $cur_room_id;
            }
            $players_out[$pid] = $p;
        }

        $by_room = function ($list) use ($cur_room_id, $state) {
            if ($cur_room_id === null || $cur_room_id === '') return $list;
            // ★ Гарантируем ROOM_ID в контракте: сущность без комнаты (старый
            // без ROOM_ID) относится к legacy-комнате — берём её из карты
            // state['room'], иначе из данных самой сущности. Без поля клиент
            // рисовал такие объекты во всех комнатах сразу (баг дублирования).
            $legacy_room_id = $state['room']['room_id'] ?? null;
            $ensure = function ($e) use ($legacy_room_id) {
                if (($e['ROOM_ID'] ?? '') === '' && $legacy_room_id !== null) {
                    $e['ROOM_ID'] = $legacy_room_id;
                }
                return $e;
            };
            $out = [];
            foreach ($list as $k => $e) {
                $e = $ensure($e);
                if (($e['ROOM_ID'] ?? null) === $cur_room_id) $out[$k] = $e;
            }
            return $out;
        };

        // ★ NPC тоже фильтруются по комнате (как objects/traps). Раньше npc_list
        // отдавался целиком: клиент рисовал турели вражеских спавнов поверх стен
        // видимой комнаты (дублирование сущностей по совпадающим координатам).
        // Страховка «NPC без ROOM_ID» сохранена: такой NPC относится к текущей
        // комнате запроса (legacy-данные), но не ко всем комнатам сразу.
        $npcs_by_room = function ($list) use ($cur_room_id) {
            if ($cur_room_id === null || $cur_room_id === '') return $list;
            $out = [];
            foreach ($list as $k => $n) {
                if (($n['ROOM_ID'] ?? '') === '') $n['ROOM_ID'] = $cur_room_id;
                if ($n['ROOM_ID'] === $cur_room_id) $out[$k] = $n;
            }
            return $out;
        };

        return [
            'version' => $version,
            'rules' => rules_client_payload(),
            // ★ Синхронизация часов (лечит «телепорт под конец маршрута»):
            // MOVE_START/END_TIME — серверные microtime(true). Клиент обязан
            // интерполировать движение по СЕРВЕРНЫМ часам, а не по локальному
            // моменту получения ответа: между тиком сервера и доставкой ответа
            // проходит сетевой RTT, и «часы отсчёта с нуля при получении»
            // отстают ровно на эту задержку — спрайт опаздывает на финишную
            // клетку и в конце пути допрыгивает рывком. Поле = серверные часы
            // на момент сборки ответа (мбсек с эпохи, та же шкала что MOVE_*_TIME).
            'server_now' => microtime(true),
            'room' => $room,
            'room_id' => $cur_room_id,
            'grid' => $room['grid'] ?? [],
            'objects' => $by_room($state['objects']),
            'traps' => $by_room($state['traps']),
            'npcs' => $npcs_by_room($state['npcs']),
            'players' => $players_out,
            'projectiles' => $state['projectiles'] ?? [],
            'log' => array_slice($state['log'], -30),
            'turn_time' => $state['turn_time'],
            'turn_duration' => TURN_DURATION,
            'time_to_next_turn' => max(0, TURN_DURATION - ($now - (int)$state['turn_time'])),
            'victory' => engine_check_victory($state),
        ];
    }
}
