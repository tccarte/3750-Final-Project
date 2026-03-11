<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

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
    $displayName = trim($input['displayName'] ?? '');
    echo json_encode(['success' => true, 'stats' => getPlayerStats($displayName)]);
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

    $displayName = trim($input['displayName'] ?? 'Guest');
    $player = getOrCreatePlayer($displayName);
    $_SESSION['player_id']    = $player['playerId'];
    $_SESSION['display_name'] = $player['displayName'];

    $gameId = generateUUID();
    $_SESSION['game_id'] = $gameId;

    $db = getDB();
    $db->prepare("INSERT INTO games (gameId, gameMode, startedAt) VALUES (?, ?, NOW())")
       ->execute([$gameId, $gameMode]);
    $db->prepare("INSERT INTO game_participants (gameId, playerId) VALUES (?, ?)")
       ->execute([$gameId, $player['playerId']]);

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
    $gameMode  = $_SESSION['game_mode'] ?? 'ai';
    $gameId    = $_SESSION['game_id']   ?? null;
    $playerId  = $_SESSION['player_id'] ?? null;
    $stats     = null;

    if ($gameMode === 'ai' && $gameId && $playerId) {
        $playerWon  = isset($input['playerWon']) ? (bool)$input['playerWon'] : false;
        $shotsFired = $_SESSION['shots'] ?? 0;
        $hitsCount  = count($_SESSION['player_hits'] ?? []);
        $won        = $playerWon ? 1 : 0;

        $db = getDB();

        $db->prepare("UPDATE games SET endedAt = NOW(), winnerId = ? WHERE gameId = ?")
           ->execute([$playerWon ? $playerId : null, $gameId]);

        $db->prepare("UPDATE game_participants SET shots = ?, hits = ?, won = ? WHERE gameId = ? AND playerId = ?")
           ->execute([$shotsFired, $hitsCount, $won, $gameId, $playerId]);

        $db->prepare("UPDATE players SET totalGames = totalGames + 1, totalWins = totalWins + ?, totalLosses = totalLosses + ?, totalMoves = totalMoves + ? WHERE playerId = ?")
           ->execute([$won, 1 - $won, $shotsFired, $playerId]);

        $stmt = $db->prepare("SELECT playerId, displayName, createdAt, totalGames, totalWins, totalLosses, totalMoves FROM players WHERE playerId = ?");
        $stmt->execute([$playerId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    unset($_SESSION['player_ships'], $_SESSION['computer_ships'],
          $_SESSION['player_hits'], $_SESSION['player_misses'],
          $_SESSION['computer_hits'], $_SESSION['computer_misses'],
          $_SESSION['shots'], $_SESSION['p2_shots'], $_SESSION['game_mode'],
          $_SESSION['ai_target_queue'], $_SESSION['ai_last_hit'],
          $_SESSION['game_id'], $_SESSION['player_id'], $_SESSION['display_name']);

    echo json_encode(['success' => true, 'stats' => $stats]);
}

function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function getOrCreatePlayer($displayName) {
    if ($displayName === '') $displayName = 'Guest';
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM players WHERE displayName = ?");
    $stmt->execute([$displayName]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$player) {
        $playerId = generateUUID();
        $db->prepare("INSERT INTO players (playerId, displayName, createdAt) VALUES (?, ?, NOW())")
           ->execute([$playerId, $displayName]);
        $player = [
            'playerId'    => $playerId,
            'displayName' => $displayName,
            'createdAt'   => date('Y-m-d H:i:s'),
            'totalGames'  => 0,
            'totalWins'   => 0,
            'totalLosses' => 0,
            'totalMoves'  => 0,
        ];
    }
    return $player;
}

function getPlayerStats($displayName) {
    if ($displayName === '') return null;
    $db   = getDB();
    $stmt = $db->prepare("SELECT playerId, displayName, createdAt, totalGames, totalWins, totalLosses, totalMoves FROM players WHERE displayName = ?");
    $stmt->execute([$displayName]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function resetStats() {
    echo json_encode(['success' => false, 'message' => 'Stats reset not supported with database storage']);
}
?>