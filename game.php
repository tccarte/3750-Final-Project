<?php
session_start();
header('Content-Type: application/json');

define('DATA_DIR', __DIR__ . '/data');
define('STATS_FILE', DATA_DIR . '/stats.json');

// Ensure data directory exists
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// Route actions
if ($action === 'init') {
    initGame($input);
} elseif ($action === 'fire') {
    fireShot($input['coord'] ?? '');
} elseif ($action === 'ai_fire') {
    aiFireShot();
} elseif ($action === 'fire_p2') {
    fireShotP2($input['coord'] ?? '');
} elseif ($action === 'ship_status') {
    echo json_encode(['success' => true, 'shipStatus' => buildShipStatus()]);
} elseif ($action === 'debug') {
    debug();
} elseif ($action === 'end_game') {
    endGame();
} elseif ($action === 'get_stats') {
    echo json_encode(['success' => true, 'stats' => readStats()]);
} elseif ($action === 'reset_stats') {
    resetStats();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

// ===================== SHIP DEFINITIONS =====================

function getShipDefinitions() {
    return [
        ['type' => 'carrier',    'size' => 5],
        ['type' => 'battleship', 'size' => 4],
        ['type' => 'cruiser',    'size' => 3],
        ['type' => 'submarine',  'size' => 3],
        ['type' => 'destroyer',  'size' => 2],
    ];
}

// ===================== INIT =====================

function initGame($input) {
    $gameMode = $input['gameMode'] ?? 'ai';
    $_SESSION['game_mode'] = $gameMode;

    $playerShips = $input['playerShips'] ?? [];
    $_SESSION['player_ships'] = convertClientShipsToServerFormat($playerShips);

    if ($gameMode === 'pvp') {
        $p2Ships = $input['p2Ships'] ?? [];
        $_SESSION['computer_ships'] = convertClientShipsToServerFormat($p2Ships);
    } else {
        $_SESSION['computer_ships'] = placeShipsRandomly();
    }

    $_SESSION['player_hits'] = [];
    $_SESSION['player_misses'] = [];
    $_SESSION['computer_hits'] = [];
    $_SESSION['computer_misses'] = [];
    $_SESSION['shots'] = 0;
    $_SESSION['p2_shots'] = 0;
    $_SESSION['ai_target_queue'] = [];
    $_SESSION['ai_last_hit'] = null;

    echo json_encode([
        'success' => true,
        'message' => 'Game initialized',
        'shipStatus' => buildShipStatus()
    ]);
}

function convertClientShipsToServerFormat($clientShips) {
    $ships = [];
    foreach ($clientShips as $ship) {
        $ships[] = [
            'type' => $ship['type'],
            'size' => $ship['size'],
            'hits' => 0,
            'positions' => $ship['positions']
        ];
    }
    return $ships;
}

// ===================== RANDOM PLACEMENT =====================

function placeShipsRandomly() {
    $ships = [];
    $defs = getShipDefinitions();
    $board = array_fill(0, 10, array_fill(0, 10, false));

    foreach ($defs as $def) {
        $placed = false;
        $attempts = 0;
        while (!$placed && $attempts < 200) {
            $orientation = rand(0, 1) === 0 ? 'H' : 'V';
            $row = rand(0, 9);
            $col = rand(0, 9);
            if (canPlaceShip($board, $row, $col, $def['size'], $orientation)) {
                $ship = placeShipOnBoard($board, $row, $col, $def['size'], $orientation);
                $ship['type'] = $def['type'];
                $ships[] = $ship;
                $placed = true;
            }
            $attempts++;
        }
    }
    return $ships;
}

function canPlaceShip($board, $row, $col, $size, $orientation) {
    if ($orientation === 'H') {
        if ($col + $size > 10) return false;
        for ($i = 0; $i < $size; $i++) {
            if ($board[$row][$col + $i]) return false;
        }
    } else {
        if ($row + $size > 10) return false;
        for ($i = 0; $i < $size; $i++) {
            if ($board[$row + $i][$col]) return false;
        }
    }
    return true;
}

function placeShipOnBoard(&$board, $row, $col, $size, $orientation) {
    $ship = ['size' => $size, 'hits' => 0, 'positions' => []];
    if ($orientation === 'H') {
        for ($i = 0; $i < $size; $i++) {
            $board[$row][$col + $i] = true;
            $ship['positions'][] = rowColToCoord($row, $col + $i);
        }
    } else {
        for ($i = 0; $i < $size; $i++) {
            $board[$row + $i][$col] = true;
            $ship['positions'][] = rowColToCoord($row + $i, $col);
        }
    }
    return $ship;
}

function rowColToCoord($row, $col) {
    $rows = ['A','B','C','D','E','F','G','H','I','J'];
    return $rows[$row] . ($col + 1);
}

// ===================== PLAYER FIRES AT COMPUTER =====================

function fireShot($coord) {
    if (!isset($_SESSION['computer_ships'])) {
        echo json_encode(['success' => false, 'message' => 'No active game']);
        return;
    }
    if (!preg_match('/^[A-J]([1-9]|10)$/', $coord)) {
        echo json_encode(['success' => false, 'message' => 'Invalid coordinate']);
        return;
    }
    if (in_array($coord, $_SESSION['player_hits']) || in_array($coord, $_SESSION['player_misses'])) {
        echo json_encode(['success' => false, 'message' => 'Already fired at this position']);
        return;
    }

    $_SESSION['shots']++;
    $hit = false;
    $sunk = false;
    $sunkShipType = null;

    $ships = $_SESSION['computer_ships'];
    foreach ($ships as &$ship) {
        if (in_array($coord, $ship['positions'])) {
            $hit = true;
            $ship['hits']++;
            $_SESSION['player_hits'][] = $coord;
            if ($ship['hits'] >= $ship['size']) {
                $sunk = true;
                $sunkShipType = $ship['type'] ?? null;
            }
            break;
        }
    }
    unset($ship);
    $_SESSION['computer_ships'] = $ships;

    if (!$hit) {
        $_SESSION['player_misses'][] = $coord;
    }

    $gameOver = checkAllSunk($_SESSION['computer_ships']);

    $response = [
        'success' => true,
        'result' => $hit ? ($sunk ? 'sunk' : 'hit') : 'miss',
        'coord' => $coord,
        'gameOver' => $gameOver,
        'sunk' => $sunk,
        'shipStatus' => buildShipStatus()
    ];
    if ($sunk && $sunkShipType) {
        $response['sunkShipType'] = $sunkShipType;
    }
    echo json_encode($response);
}

// ===================== AI FIRES AT PLAYER =====================

function aiFireShot() {
    if (!isset($_SESSION['player_ships'])) {
        echo json_encode(['success' => false, 'message' => 'No active game']);
        return;
    }
    if (!isset($_SESSION['ai_target_queue'])) $_SESSION['ai_target_queue'] = [];
    if (!isset($_SESSION['ai_last_hit'])) $_SESSION['ai_last_hit'] = null;

    $coord = null;
    if (!empty($_SESSION['ai_target_queue'])) {
        $coord = array_shift($_SESSION['ai_target_queue']);
        while ($coord && (in_array($coord, $_SESSION['computer_hits']) || in_array($coord, $_SESSION['computer_misses']))) {
            $coord = !empty($_SESSION['ai_target_queue']) ? array_shift($_SESSION['ai_target_queue']) : null;
        }
    }
    if (!$coord) {
        $available = getAvailableCells();
        if (empty($available)) {
            echo json_encode(['success' => false, 'message' => 'No cells available']);
            return;
        }
        $coord = $available[array_rand($available)];
    }

    $hit = false;
    $sunk = false;
    $sunkShipType = null;

    $ships = $_SESSION['player_ships'];
    foreach ($ships as &$ship) {
        if (in_array($coord, $ship['positions'])) {
            $hit = true;
            $ship['hits']++;
            $_SESSION['computer_hits'][] = $coord;
            $_SESSION['ai_last_hit'] = $coord;
            if ($ship['hits'] >= $ship['size']) {
                $sunk = true;
                $sunkShipType = $ship['type'] ?? null;
                $_SESSION['ai_target_queue'] = [];
                $_SESSION['ai_last_hit'] = null;
            } else {
                addAdjacentTargets($coord);
            }
            break;
        }
    }
    unset($ship);
    $_SESSION['player_ships'] = $ships;

    if (!$hit) {
        $_SESSION['computer_misses'][] = $coord;
    }

    $gameOver = checkAllSunk($_SESSION['player_ships']);

    $response = [
        'success' => true,
        'result' => $hit ? ($sunk ? 'sunk' : 'hit') : 'miss',
        'coord' => $coord,
        'gameOver' => $gameOver,
        'sunk' => $sunk,
        'shipStatus' => buildShipStatus()
    ];
    if ($sunk && $sunkShipType) {
        $response['sunkShipType'] = $sunkShipType;
    }
    echo json_encode($response);
}

// ===================== P2 FIRES AT P1 =====================

function fireShotP2($coord) {
    if (!isset($_SESSION['player_ships'])) {
        echo json_encode(['success' => false, 'message' => 'No active game']);
        return;
    }
    if (!preg_match('/^[A-J]([1-9]|10)$/', $coord)) {
        echo json_encode(['success' => false, 'message' => 'Invalid coordinate']);
        return;
    }
    if (in_array($coord, $_SESSION['computer_hits']) || in_array($coord, $_SESSION['computer_misses'])) {
        echo json_encode(['success' => false, 'message' => 'Already fired at this position']);
        return;
    }

    $_SESSION['p2_shots']++;
    $hit = false;
    $sunk = false;
    $sunkShipType = null;

    $ships = $_SESSION['player_ships'];
    foreach ($ships as &$ship) {
        if (in_array($coord, $ship['positions'])) {
            $hit = true;
            $ship['hits']++;
            $_SESSION['computer_hits'][] = $coord;
            if ($ship['hits'] >= $ship['size']) {
                $sunk = true;
                $sunkShipType = $ship['type'] ?? null;
            }
            break;
        }
    }
    unset($ship);
    $_SESSION['player_ships'] = $ships;

    if (!$hit) {
        $_SESSION['computer_misses'][] = $coord;
    }

    $gameOver = checkAllSunk($_SESSION['player_ships']);

    $response = [
        'success' => true,
        'result' => $hit ? ($sunk ? 'sunk' : 'hit') : 'miss',
        'coord' => $coord,
        'gameOver' => $gameOver,
        'sunk' => $sunk,
        'shipStatus' => buildShipStatus()
    ];
    if ($sunk && $sunkShipType) {
        $response['sunkShipType'] = $sunkShipType;
    }
    echo json_encode($response);
}

// ===================== SHIP STATUS =====================

function buildShipStatus() {
    $status = ['player' => [], 'enemy' => []];
    if (isset($_SESSION['player_ships'])) {
        foreach ($_SESSION['player_ships'] as $ship) {
            $status['player'][] = [
                'type' => $ship['type'] ?? 'unknown',
                'size' => $ship['size'],
                'sunk' => $ship['hits'] >= $ship['size']
            ];
        }
    }
    if (isset($_SESSION['computer_ships'])) {
        foreach ($_SESSION['computer_ships'] as $ship) {
            $status['enemy'][] = [
                'type' => $ship['type'] ?? 'unknown',
                'size' => $ship['size'],
                'sunk' => $ship['hits'] >= $ship['size']
            ];
        }
    }
    return $status;
}

function checkAllSunk($ships) {
    foreach ($ships as $ship) {
        if ($ship['hits'] < $ship['size']) return false;
    }
    return true;
}

// ===================== HELPERS =====================

function getAvailableCells() {
    $cells = [];
    $rows = ['A','B','C','D','E','F','G','H','I','J'];
    for ($i = 0; $i < 10; $i++) {
        for ($j = 0; $j < 10; $j++) {
            $coord = $rows[$i] . ($j + 1);
            if (!in_array($coord, $_SESSION['computer_hits']) && !in_array($coord, $_SESSION['computer_misses'])) {
                $cells[] = $coord;
            }
        }
    }
    return $cells;
}

function addAdjacentTargets($coord) {
    $rows = ['A'=>0,'B'=>1,'C'=>2,'D'=>3,'E'=>4,'F'=>5,'G'=>6,'H'=>7,'I'=>8,'J'=>9];
    $letters = ['A','B','C','D','E','F','G','H','I','J'];
    $row = $rows[$coord[0]];
    $col = (int)substr($coord, 1) - 1;
    $adj = [];
    if ($row > 0) $adj[] = $letters[$row-1].($col+1);
    if ($row < 9) $adj[] = $letters[$row+1].($col+1);
    if ($col > 0) $adj[] = $letters[$row].$col;
    if ($col < 9) $adj[] = $letters[$row].($col+2);
    foreach ($adj as $cell) {
        if (!in_array($cell, $_SESSION['computer_hits']) &&
            !in_array($cell, $_SESSION['computer_misses']) &&
            !in_array($cell, $_SESSION['ai_target_queue'])) {
            $_SESSION['ai_target_queue'][] = $cell;
        }
    }
}

// ===================== DEBUG =====================

function debug() {
    if (!isset($_SESSION['player_ships'])) {
        echo json_encode(['success' => false, 'message' => 'No active game']);
        return;
    }
    echo json_encode([
        'success' => true,
        'player_ships' => $_SESSION['player_ships'],
        'computer_ships' => $_SESSION['computer_ships'],
        'player_hits' => $_SESSION['player_hits'] ?? [],
        'player_misses' => $_SESSION['player_misses'] ?? [],
        'computer_hits' => $_SESSION['computer_hits'] ?? [],
        'computer_misses' => $_SESSION['computer_misses'] ?? [],
        'ai_state' => [
            'target_queue' => $_SESSION['ai_target_queue'] ?? [],
            'last_hit' => $_SESSION['ai_last_hit'] ?? null,
            'mode' => empty($_SESSION['ai_target_queue']) ? 'HUNT' : 'TARGET'
        ]
    ]);
}

// ===================== END GAME & STATS =====================

function endGame() {
    global $input;
    $gameMode = $_SESSION['game_mode'] ?? 'ai';
    $stats = null;

    if ($gameMode === 'ai') {
        $playerWon = isset($input['playerWon']) ? (bool)$input['playerWon'] : false;
        $shotsFired = $_SESSION['shots'] ?? 0;
        $hitsCount = count($_SESSION['player_hits'] ?? []);
        $stats = updateStats($playerWon, $shotsFired, $hitsCount);
    }

    unset($_SESSION['player_ships'], $_SESSION['computer_ships'],
          $_SESSION['player_hits'], $_SESSION['player_misses'],
          $_SESSION['computer_hits'], $_SESSION['computer_misses'],
          $_SESSION['shots'], $_SESSION['p2_shots'], $_SESSION['game_mode'],
          $_SESSION['ai_target_queue'], $_SESSION['ai_last_hit']);

    echo json_encode(['success' => true, 'stats' => $stats]);
}

function getDefaults() {
    return [
        'totalGames' => 0, 'wins' => 0, 'losses' => 0,
        'totalShots' => 0, 'totalHits' => 0, 'bestAccuracy' => 0,
        'currentWinStreak' => 0, 'bestWinStreak' => 0
    ];
}

function readStats() {
    $defaults = getDefaults();
    if (!file_exists(STATS_FILE)) {
        writeStats($defaults);
        return $defaults;
    }
    $contents = @file_get_contents(STATS_FILE);
    if ($contents === false) return $defaults;
    $stats = json_decode($contents, true);
    return is_array($stats) ? array_merge($defaults, $stats) : $defaults;
}

function writeStats($stats) {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    $result = @file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX);
    if ($result !== false) {
        @chmod(STATS_FILE, 0666);
    } else {
        error_log('Battleship: Cannot write stats to ' . STATS_FILE);
    }
}

function updateStats($playerWon, $shots, $hitsCount) {
    $stats = readStats();
    $stats['totalGames']++;
    $stats['totalShots'] += $shots;
    $stats['totalHits'] += $hitsCount;
    if ($playerWon) {
        $stats['wins']++;
        $stats['currentWinStreak']++;
        if ($stats['currentWinStreak'] > $stats['bestWinStreak']) {
            $stats['bestWinStreak'] = $stats['currentWinStreak'];
        }
        $accuracy = $shots > 0 ? round(($hitsCount / $shots) * 100, 1) : 0;
        if ($accuracy > $stats['bestAccuracy']) {
            $stats['bestAccuracy'] = $accuracy;
        }
    } else {
        $stats['losses']++;
        $stats['currentWinStreak'] = 0;
    }
    writeStats($stats);
    return $stats;
}

function resetStats() {
    $defaults = getDefaults();
    writeStats($defaults);
    echo json_encode(['success' => true, 'stats' => $defaults]);
}
?>