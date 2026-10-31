<?php
/**
 * action.php — HTTP-эндпоинт действий игроков (тонкий слой).
 *
 * Принимает POST JSON {action, player, ...} и маршрутизирует в движок:
 *   move    → systems/movement
 *   attack  → systems/combat
 *   ability → systems/abilities (+ трата действия/статус/TURN здесь, т.к.
 *             это «обёртка глагола», а не боевая механика)
 *   special → тот же путь, что ability, но статус SPECIAL: третья способность
 *             в списке игрока — неизменная «специальная» способность персонажа
 *             (в будущем выбор персонажей: 1 статичная special + 2 кастомных).
 *             Механика у неё обычная способность из реестра, отличается только
 *             проигрываемый клиентом кадр анимации.
 * Сохранение — всегда через world_save() (единая точка инкремента версии).
 */

// Буферизация вывода
ob_start();

require_once __DIR__ . '/engine/bootstrap.php';

setCorsHeaders();

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $raw_input = requestInput();
    $input = json_decode($raw_input, true);

    if (!$input) {
        jsonError('Неверный формат данных. Ожидается JSON. Получено: ' . substr($raw_input, 0, 100));
    }

    $action = $input['action'] ?? null;
    $player_id = $input['player'] ?? null;

    if (!$action || !$player_id) {
        jsonError('Не указан action или player');
    }

    // Идемпотентность при ретраях («потеря пакета»): клиент генерирует уникальный
    // action_id для каждого действия и повторяет запрос с ТЕМ ЖЕ id, если ответ
    // потерян. Если сервер уже применил это действие — повторно не применяем,
    // а отдаём сохранённое подтверждение (см. world_action_ack_get/set).
    $action_id = isset($input['action_id']) ? substr((string)$input['action_id'], 0, 64) : null;
    $ack = world_action_ack_get($player_id, $action_id);
    if ($ack !== null && !empty($ack['success'])) {
        closeRedis();
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => $ack['message'] ?? 'Действие уже выполнено',
            'version' => world_version(),
            'duplicate' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    [$state, $base_version] = world_load_versioned();

    if (!isset($state['players'][$player_id])) {
        // 404-подобная ситуация: раньше здесь бросался необработанный случай → HTTP 500
        closeRedis();
        jsonError('Игрок не найден: ' . $player_id . '. Сначала инициализируйте бой через init.php', 400);
    }

    // Единая валидация глагола ДО любой мутации состояния (dead/stunned/moving/no-actions).
    // Ловит баги рефакторинга: «мёртвый применяет действия», «залипший STUNNED».
    // special — отдельный глагол (свой словарь блокировок в effects_blocks и свой
    // статус анимации SPECIAL), но ИСПОЛНЯЕТСЯ по пути ability: механика у него
    // обычная способность из реестра, отличается только кадр анимации.
    $known_actions = ['move', 'attack', 'ability', 'special'];
    if (!in_array($action, $known_actions, true)) {
        closeRedis();
        jsonError('Неизвестное действие: ' . $action, 400);
    }

    $target_x = (int)($input['x'] ?? -1);
    $target_y = (int)($input['y'] ?? -1);
    $ability_name = $input['ability'] ?? null;

    // ─── ПРИМЕНЕНИЕ ДЕЙСТВИЯ (цикл CAS / optimistic concurrency) ────────────
    // ★ ИСПРАВЛЕНИЕ серверной половины бага «потери пакета» (last-writer-wins):
    // poll.php тикает параллельно (отдельный PHP-процесс) и раньше мог
    // безусловно перезаписать мир снапшотом, загруженным ДО нашего действия —
    // игрок получал «Действие выполнено», а мир откатывал изменение назад.
    // Теперь сохранение — compare-and-swap по версии, прочитанной при загрузке
    // (world_save_cas). Если мир успел измениться — НЕ затираем чужую запись,
    // а перечитываем свежее состояние, заново валидируем и применяем то же
    // действие поверх него (rebase) и повторяем сохранение. Идемпотентность по
    // action_id выше по-прежнему защищает от двойного применения ретраев.
    // move|attack|ability|special — каждый глагол проверяется своим ключом
    // effects_blocks (спецэффекты могут блокировать их выборочно); special
    // исполняется как ability (switch fallthrough), разница лишь в статусе анимации.
    $verb = $action;
    $exec_verb = ($action === 'special') ? 'ability' : $action;
    $result = ['success' => false, 'message' => 'Неизвестное действие'];
    $saved = false;

    for ($cas_attempt = 0; $cas_attempt < 3; $cas_attempt++) {
        $v = action_validate($state, $player_id, $verb);
        if (!$v['success']) {
            closeRedis();
            // Отклонение действия — это штатная ситуация, а не серверная ошибка: 400 + JSON
            jsonError($v['message'], 400);
        }

        switch ($action) {
            case 'move':
                if (!isValidPosition($target_x, $target_y)) {
                    $result = ['success' => false, 'message' => 'Неверные координаты'];
                    break;
                }
                $result = movement_process_move($state, $player_id, $target_x, $target_y);
                break;

            case 'attack':
                if (!isValidPosition($target_x, $target_y)) {
                    $result = ['success' => false, 'message' => 'Неверные координаты'];
                    break;
                }
                $result = combat_process_attack($state, $player_id, $target_x, $target_y);
                break;

            case 'ability':
            case 'special':
                // Механика общая (реестр способностей + applyAbility); special —
                // третья способность в списке игрока, её кадр анимации другой.
                if (!$ability_name) {
                    $result = ['success' => false, 'message' => 'Не указана способность'];
                    break;
                }

                // Способность должна быть у игрока (строка "NAME[range]")
                $has_ability = false;
                foreach ($state['players'][$player_id]['ABILITIES'] as $ab) {
                    if (strpos($ab, $ability_name) === 0) { $has_ability = true; break; }
                }
                if (!$has_ability) {
                    $result = ['success' => false, 'message' => 'Способность не доступна'];
                    break;
                }

                if ($state['players'][$player_id]['ACTIONS'] <= 0) {
                    $result = ['success' => false, 'message' => 'Нет очков действий'];
                    break;
                }
                if ($state['players'][$player_id]['STATUS'] === 'MOVING') {
                    $result = ['success' => false, 'message' => 'Нельзя использовать способность во время движения'];
                    break;
                }
                // CC-блокировка способности — спрашиваем effects (не парсим NEGATIVES).
                // special — самостоятельный глагол в словаре blocks (effects.php),
                // блокируется теми же cc-эффектами, что ability. Сообщение об отказе
                // берём из реестра эффектов (игровое, а не техническое).
                $block_msg = effects_block_message($state['players'][$player_id], $exec_verb);
                if ($block_msg !== '') {
                    $result = ['success' => false, 'message' => $block_msg];
                    break;
                }
                if ($state['players'][$player_id]['STATUS'] === 'STUNNED') {
                    $result = ['success' => false, 'message' => 'Игрок оглушен'];
                    break;
                }

                $ability_result = applyAbility($state, $player_id, $ability_name, $target_x, $target_y);

                if ($ability_result['success']) {
                    $state['players'][$player_id]['ACTIONS']--;
                    // Кадр анимации: special (третья способность) → SPECIAL, остальные → ABILITY.
                    $state['players'][$player_id]['STATUS'] = ($action === 'special') ? 'SPECIAL' : 'ABILITY';
                    $state['players'][$player_id]['ATTACK_TIME'] = time();
                    // TURN — каноническая формула из engine/rules (только лево/право)
                    $ab_pos = parseXY($state['players'][$player_id]['CUR_POSITION_XY']);
                    $new_turn = rules_turn_formula($ab_pos['x'], $target_x, $state['players'][$player_id]['TURN']);
                    if ($new_turn !== null) $state['players'][$player_id]['TURN'] = $new_turn;
                }
                $result = $ability_result;
                break;

            default:
                $result = ['success' => false, 'message' => 'Неизвестное действие: ' . $action];
        }

        if (!$result['success']) break; // штатный отказ правил — сохраняем не нужно

        $state = world_log($state, $result['message']);

        // Финализация начала движения: фиксируем виртуальные часы мира, чтобы
        // catch-up-тик в poll прошёл весь путь клетка-за-клеткой (ловушки и
        // зоны угрозы на промежуточных клетках обязаны сработать даже если
        // клиент вернётся к серверу уже после анимации хода).
        if ($action === 'move') {
            movement_finalize_start($state, $player_id);
        }

        // ★ Критично для «потери пакета»: save обязан реально записать
        // состояние — если Redis-соединение почему-то закрыто, getRedis()
        // восстановит его (ленивое переподключение); ошибки соединения ловятся
        // внешним catch (возврат 5xx → клиент ретраит идемпотентно).
        try {
            [$saved, $fresh_state] = world_save_cas($state, $base_version);
        } catch (Exception $e) {
            closeRedis();
            jsonError('Не удалось сохранить состояние: ' . $e->getMessage());
        }

        if ($saved) break;

        // ГОНКА: параллельный процесс изменил мир между load и save.
        // Продолжаем работу на свежезагруженном состоянии и повторяем
        // валидацию+мутацию+сохранение поверх него.
        $state = $fresh_state;
        $base_version = (int)($state['version'] ?? 0);
    }

    if ($result['success'] && $saved) {

        // Версию читаем ДО закрытия соединения — раньше getRedis() после
        // closeRedis() возвращал null → фатал, успешное действие «терялось»
        // для клиента (битый ответ → retry → конфликт повторного применения).
        $new_version = world_version();

        // Подтверждение идемпотентности: сохраняем результат действия, чтобы
        // повтор с тем же action_id не применился вторично.
        world_action_ack_set($player_id, $action_id, [
            'success' => true,
            'message' => $result['message'],
            'version' => $new_version,
        ]);

        closeRedis();
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => $result['message'],
            'version' => $new_version,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } else if ($result['success'] && !$saved) {
        // Успешно смоделировано, но 3 подряд гонки CAS — мир слишком горяч.
        // 5xx: клиент ретраит с тем же action_id, состояние не испорчено.
        closeRedis();
        jsonError('Мир изменён параллельным процессом, повторите действие', 503);
    } else {
        closeRedis();
        jsonError($result['message'], 400);
    }

} catch (Exception $e) {
    // ВАЖНО: этот блок достижим и ПОСЛЕ closeRedis() (например исключение из
    // финального echo/заголовков). jsonError→jsonResponse не должен трогать
    // Redis — иначе getRedis() на мёртвом соединении кидал новое исключение
    // прямо в echo, обрывая HTTP-ответ: клиент считал это «потерей пакета».
    clearOutputBuffers();
    sendJsonErrorFallback('Ошибка обработки действия: ' . $e->getMessage());
} catch (Throwable $e) {
    clearOutputBuffers();
    sendJsonErrorFallback('Критическая ошибка: ' . $e->getMessage());
}
