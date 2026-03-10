<?php
session_start();
header('Content-Type: application/json');

define('STATS_FILE', __DIR__ . '/stats.json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// Handle actions
if ($action === 'init') {
    initGame($input);
} elseif ($action === 'fire') {
    $coord = $input['coord'] ?? '';
    fireShot($coord);
} elseif ($action === 'ai_fire') {
    aiFireShot();
} elseif ($action === 'fire_p2') {
    $coord = $input['coord'] ?? '';
    fireShotP2($coord);
} elseif ($action === 'debug') {
    debug();
} elseif ($action === 'end_game') {
    endGame();
} elseif ($action === 'get_stats') {
    echo json_encode(['success' => true, 'stats' => readStats()]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

// Initialize a new game
function initGame($input) {
    $gameMode = $input['gameMode'] ?? 'ai';
    $_SESSION['game_mode'] = $gameMode;

    // Store player 1 ships
    $playerShips = $input['playerShips'] ?? [];
    $_SESSION['player_ships'] = convertClientShipsToServerFormat($playerShips);

    // Player 2 ships: from input (PvP) or random (AI)
    if ($gameMode === 'pvp') {
        $p2Ships = $input['p2Ships'] ?? [];
        $_SESSION['computer_ships'] = convertClientShipsToServerFormat($p2Ships);
    } else {
        $_SESSION['computer_ships'] = placeShipsRandomly();
    }

    // Initialize tracking arrays
    $_SESSION['player_hits'] = [];
    $_SESSION['player_misses'] = [];
    $_SESSION['computer_hits'] = [];
    $_SESSION['computer_misses'] = [];
    $_SESSION['shots'] = 0;
    $_SESSION['p2_shots'] = 0;

    echo json_encode([
        'success' => true,
        'message' => 'Game initialized'
    ]);
}

// Convert client ship format to server format
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

// Place ships randomly on the board
function placeShipsRandomly() {
    $ships = [];
    $shipSizes = [5, 3, 2]; // Carrier, Cruiser, Destroyer
    $board = array_fill(0, 10, array_fill(0, 10, false));

    foreach ($shipSizes as $size) {
        $placed = false;
        $attempts = 0;

        while (!$placed && $attempts < 100) {
            $orientation = rand(0, 1) === 0 ? 'H' : 'V';
            $row = rand(0, 9);
            $col = rand(0, 9);

            if (canPlaceShip($board, $row, $col, $size, $orientation)) {
                $ship = placeShip($board, $row, $col, $size, $orientation);
                $ships[] = $ship;
                $placed = true;
            }
            $attempts++;
        }
    }

    return $ships;
}

// Check if a ship can be placed at the given position
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

// Place a ship on the board
function placeShip(&$board, $row, $col, $size, $orientation) {
    $ship = [
        'size' => $size,
        'hits' => 0,
        'positions' => []
    ];

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

// Convert row/col to coordinate
function rowColToCoord($row, $col) {
    $rows = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
    return $rows[$row] . ($col + 1);
}

// Fire a shot at computer's board
function fireShot($coord) {
    if (!isset($_SESSION['computer_ships'])) {
        echo json_encode(['success' => false, 'message' => 'No active game']);
        return;
    }

    // Validate coordinate
    if (!preg_match('/^[A-J]([1-9]|10)$/', $coord)) {
        echo json_encode(['success' => false, 'message' => 'Invalid coordinate']);
        return;
    }

    // Check if already fired
    if (in_array($coord, $_SESSION['player_hits']) || in_array($coord, $_SESSION['player_misses'])) {
        echo json_encode(['success' => false, 'message' => 'Already fired at this position']);
        return;
    }

    $_SESSION['shots']++;

    // Check if hit computer ship
    $hit = false;
    $ships = $_SESSION['computer_ships'];
    foreach ($ships as $index => &$ship) {
        if (in_array($coord, $ship['positions'])) {
            $hit = true;
            $ship['hits']++;
            $_SESSION['player_hits'][] = $coord;
            break;
        }
    }
    unset($ship);
    $_SESSION['computer_ships'] = $ships;

    if (!$hit) {
        $_SESSION['player_misses'][] = $coord;
    }

    // Check if player won (all computer ships sunk)
    $gameOver = true;
    foreach ($_SESSION['computer_ships'] as $ship) {
        if ($ship['hits'] < $ship['size']) {
            $gameOver = false;
            break;
        }
    }

    echo json_encode([
        'success' => true,
        'result' => $hit ? 'hit' : 'miss',
        'coord' => $coord,
        'gameOver' => $gameOver
    ]);
}

// AI fires a shot at player's board (Smart AI with hunt/target mode)
function aiFireShot() {
    if (!isset($_SESSION['player_ships'])) {
        echo json_encode(['success' => false, 'message' => 'No active game']);
        return;
    }

    // Initialize AI state if not exists
    if (!isset($_SESSION['ai_target_queue'])) {
        $_SESSION['ai_target_queue'] = [];
    }
    if (!isset($_SESSION['ai_last_hit'])) {
        $_SESSION['ai_last_hit'] = null;
    }

    $coord = null;

    // TARGET MODE: If we have targets in queue, use them first
    if (!empty($_SESSION['ai_target_queue'])) {
        $coord = array_shift($_SESSION['ai_target_queue']);

        // Make sure this cell hasn't been fired at
        while ($coord && (in_array($coord, $_SESSION['computer_hits']) || in_array($coord, $_SESSION['computer_misses']))) {
            $coord = !empty($_SESSION['ai_target_queue']) ? array_shift($_SESSION['ai_target_queue']) : null;
        }
    }

    // HUNT MODE: Random selection if no targets queued
    if (!$coord) {
        $availableCells = getAvailableCells();
        if (empty($availableCells)) {
            echo json_encode(['success' => false, 'message' => 'No cells available']);
            return;
        }
        $coord = $availableCells[array_rand($availableCells)];
    }

    // Check if hit player ship
    $hit = false;
    $sunk = false;
    $sunkShipPositions = [];

    $ships = $_SESSION['player_ships'];
    foreach ($ships as $index => &$ship) {
        if (in_array($coord, $ship['positions'])) {
            $hit = true;
            $ship['hits']++;
            $_SESSION['computer_hits'][] = $coord;
            $_SESSION['ai_last_hit'] = $coord;

            // Check if ship is now sunk
            if ($ship['hits'] >= $ship['size']) {
                $sunk = true;
                $sunkShipPositions = $ship['positions'];

                // Ship sunk - clear target queue and last hit
                $_SESSION['ai_target_queue'] = [];
                $_SESSION['ai_last_hit'] = null;
            } else {
                // Ship hit but not sunk - add adjacent cells to target queue
                addAdjacentTargets($coord);
            }
            break;
        }
    }
    unset($ship);
    $_SESSION['player_ships'] = $ships;

    if (!$hit) {
        $_SESSION['computer_misses'][] = $coord;
        // If we missed from target queue, continue with other targets
    }

    // Check if AI won (all player ships sunk)
    $gameOver = true;
    foreach ($_SESSION['player_ships'] as $ship) {
        if ($ship['hits'] < $ship['size']) {
            $gameOver = false;
            break;
        }
    }

    echo json_encode([
        'success' => true,
        'result' => $hit ? ($sunk ? 'sunk' : 'hit') : 'miss',
        'coord' => $coord,
        'gameOver' => $gameOver,
        'sunk' => $sunk
    ]);
}

// Get available cells that haven't been fired at
function getAvailableCells() {
    $availableCells = [];
    $rows = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];

    for ($i = 0; $i < 10; $i++) {
        for ($j = 0; $j < 10; $j++) {
            $coord = $rows[$i] . ($j + 1);
            if (!in_array($coord, $_SESSION['computer_hits']) && !in_array($coord, $_SESSION['computer_misses'])) {
                $availableCells[] = $coord;
            }
        }
    }

    return $availableCells;
}

// Add adjacent cells to target queue
function addAdjacentTargets($coord) {
    $rows = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, 'E' => 4, 'F' => 5, 'G' => 6, 'H' => 7, 'I' => 8, 'J' => 9];
    $rowLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];

    $row = $rows[$coord[0]];
    $col = (int)substr($coord, 1) - 1;

    $adjacentCells = [];

    // North
    if ($row > 0) {
        $adjacentCells[] = $rowLetters[$row - 1] . ($col + 1);
    }
    // South
    if ($row < 9) {
        $adjacentCells[] = $rowLetters[$row + 1] . ($col + 1);
    }
    // West
    if ($col > 0) {
        $adjacentCells[] = $rowLetters[$row] . $col;
    }
    // East
    if ($col < 9) {
        $adjacentCells[] = $rowLetters[$row] . ($col + 2);
    }

    // Add to target queue (only if not already fired at)
    foreach ($adjacentCells as $cell) {
        if (!in_array($cell, $_SESSION['computer_hits']) &&
            !in_array($cell, $_SESSION['computer_misses']) &&
            !in_array($cell, $_SESSION['ai_target_queue'])) {
            $_SESSION['ai_target_queue'][] = $cell;
        }
    }
}

// Debug function
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
// Player 2 fires a shot at Player 1's board
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
    $ships = $_SESSION['player_ships'];
    foreach ($ships as $index => &$ship) {
        if (in_array($coord, $ship['positions'])) {
            $hit = true;
            $ship['hits']++;
            $_SESSION['computer_hits'][] = $coord;

            if ($ship['hits'] >= $ship['size']) {
                $sunk = true;
            }
            break;
        }
    }
    unset($ship);
    $_SESSION['player_ships'] = $ships;

    if (!$hit) {
        $_SESSION['computer_misses'][] = $coord;
    }

    $gameOver = true;
    foreach ($_SESSION['player_ships'] as $ship) {
        if ($ship['hits'] < $ship['size']) {
            $gameOver = false;
            break;
        }
    }

    echo json_encode([
        'success' => true,
        'result' => $hit ? ($sunk ? 'sunk' : 'hit') : 'miss',
        'coord' => $coord,
        'gameOver' => $gameOver,
        'sunk' => $sunk
    ]);
}

// End game and persist stats
function endGame() {
    if (!isset($_SESSION['computer_ships'])) {
        echo json_encode(['success' => false, 'message' => 'No active game to end']);
        return;
    }

    $gameMode = $_SESSION['game_mode'] ?? 'ai';
    $stats = null;

    if ($gameMode === 'ai') {
        // Determine winner server-side
        $playerWon = true;
        foreach ($_SESSION['computer_ships'] as $ship) {
            if ($ship['hits'] < $ship['size']) {
                $playerWon = false;
                break;
            }
        }

        $shotsFired = $_SESSION['shots'] ?? 0;
        $hitsCount = count($_SESSION['player_hits'] ?? []);
        $stats = updateStats($playerWon, $shotsFired, $hitsCount);
    }

    // Clear session to prevent double-counting
    unset($_SESSION['player_ships'], $_SESSION['computer_ships'],
          $_SESSION['player_hits'], $_SESSION['player_misses'],
          $_SESSION['computer_hits'], $_SESSION['computer_misses'],
          $_SESSION['shots'], $_SESSION['p2_shots'], $_SESSION['game_mode'],
          $_SESSION['ai_target_queue'], $_SESSION['ai_last_hit']);

    echo json_encode(['success' => true, 'stats' => $stats]);
}

// Read stats from JSON file
function readStats() {
    $defaults = [
        'totalGames' => 0,
        'wins' => 0,
        'losses' => 0,
        'totalShots' => 0,
        'totalHits' => 0,
        'bestAccuracy' => 0,
        'currentWinStreak' => 0,
        'bestWinStreak' => 0
    ];

    if (!file_exists(STATS_FILE)) {
        return $defaults;
    }

    $stats = json_decode(file_get_contents(STATS_FILE), true);
    return $stats !== null ? $stats : $defaults;
}

// Write stats to JSON file
function writeStats($stats) {
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX);
}

// Update stats after a game ends
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
?>
