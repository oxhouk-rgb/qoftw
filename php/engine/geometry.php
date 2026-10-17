<?php
/**
 * engine/geometry.php — Чистые геометрические функции мира (не мутируют состояние).
 *
 * Единственный владелец формата координат "X,Y" и сеточной математики:
 * parseXY / formatXY / isValidPosition / manhattan / cardinal path / LOS.
 * Все остальные модули обязаны использовать эти функции, а не свой парсинг.
 */

require_once __DIR__ . '/rules.php';

/** Парсинг координат "X,Y" в массив ['x'=>int,'y'=>int] */
function parseXY($xy) {
    $parts = explode(',', $xy);
    return ['x' => (int)$parts[0], 'y' => (int)$parts[1]];
}

/** Форматирование координат в строку (целые — как целые, иначе 2 знака) */
function formatXY($x, $y) {
    if (floor($x) == $x && floor($y) == $y) {
        return ((int)$x) . ',' . ((int)$y);
    }
    return round($x, 2) . ',' . round($y, 2);
}

/** Валидность клетки сетки */
function isValidPosition($x, $y) {
    return $x >= 0 && $x < GRID_WIDTH && $y >= 0 && $y < GRID_HEIGHT;
}

/** Манхэттенское расстояние между клетками */
function manhattan($x1, $y1, $x2, $y2) {
    return abs($x1 - $x2) + abs($y1 - $y2);
}

/** Клетка в радиусе (манхэттен) */
function inRadius($cx, $cy, $x, $y, $radius) {
    return manhattan($cx, $cy, $x, $y) <= $radius;
}

/**
 * Путь по прямой от A до B (без начальной клетки, с конечной).
 * Возвращает [] если точки совпадают или путь не по прямой/диагонали шагов.
 */
function calculatePath($x1, $y1, $x2, $y2) {
    $path = [];
    $dx = ($x2 > $x1) ? 1 : (($x2 < $x1) ? -1 : 0);
    $dy = ($y2 > $y1) ? 1 : (($y2 < $y1) ? -1 : 0);

    $cx = $x1 + $dx;
    $cy = $y1 + $dy;

    while (($cx !== $x2 || $cy !== $y2) && isValidPosition($cx, $cy)) {
        $path[] = ['x' => $cx, 'y' => $cy];
        $cx += $dx;
        $cy += $dy;
    }

    if ($x2 !== $x1 || $y2 !== $y1) {
        $path[] = ['x' => $x2, 'y' => $y2];
    }

    return $path;
}

/** 8 соседних клеток (включая диагонали), только валидные */
function adjacentCells($x, $y) {
    $cells = [];
    for ($dx = -1; $dx <= 1; $dx++) {
        for ($dy = -1; $dy <= 1; $dy++) {
            if ($dx === 0 && $dy === 0) continue;
            $nx = $x + $dx;
            $ny = $y + $dy;
            if (isValidPosition($nx, $ny)) {
                $cells[] = [$nx, $ny];
            }
        }
    }
    return $cells;
}

/**
 * Клетки в манхэттен-радиусе от центра (включая центр), только валидные.
 * Используется AoE-зонами и зонами угрозы.
 */
function cellsInRadius($center_x, $center_y, $radius) {
    $cells = [];
    for ($dx = -$radius; $dx <= $radius; $dx++) {
        for ($dy = -$radius; $dy <= $radius; $dy++) {
            if (abs($dx) + abs($dy) > $radius) continue;
            $x = $center_x + $dx;
            $y = $center_y + $dy;
            if (isValidPosition($x, $y)) {
                $cells[] = ['x' => $x, 'y' => $y];
            }
        }
    }
    return $cells;
}

/** Направление-шаг (dx,dy) из разницы точек */
function directionStep($x1, $y1, $x2, $y2) {
    return [
        ($x2 > $x1) ? 1 : (($x2 < $x1) ? -1 : 0),
        ($y2 > $y1) ? 1 : (($y2 < $y1) ? -1 : 0),
    ];
}
