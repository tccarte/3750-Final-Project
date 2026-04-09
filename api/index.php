<?php
require_once __DIR__ . '/../db.php';

// ===================== CONFIG =====================
define('TEST_MODE', true);
define('TEST_PASSWORD', 'clemson-test-2026');

// ===================== BOOTSTRAP =====================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Test-Password');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = preg_replace('#^.*/api#', '', $uri);
$path   = '/' . trim($uri, '/');
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ===================== ROUTER =====================
try {
    route($method, $path, $body);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'detail' => $e->getMessage()]);
}

function route(string $method, string $path, array $body): void {
    // --- Test-mode endpoints ---
    if ($method === 'POST' && preg_match('#^/test/games/(\d+)/restart$#', $path, $m)) {
        requireTestMode(); handleTestRestart((int)$m[1]); return;
    }
    if ($method === 'POST' && preg_match('#^/test/games/(\d+)/ships$#', $path, $m)) {
        requireTestMode(); handleTestPlaceShips((int)$m[1], $body); return;
    }
    if ($method === 'GET' && preg_match('#^/test/games/(\d+)/board/(\d+)$#', $path, $m)) {
        requireTestMode(); handleTestGetBoard((int)$m[1], (int)$m[2]); return;
    }

    // --- Production endpoints ---
    if ($method === 'GET'  && $path === '/health') { handleHealth(); return; }
    if ($method === 'POST' && $path === '/reset') { handleReset(); return; }
    if ($method === 'POST' && $path === '/players') { handleCreatePlayer($body); return; }
    if ($method === 'GET'  && preg_match('#^/players/(\d+)/stats$#', $path, $m)) { handleGetStats((int)$m[1]); return; }
    if ($method === 'POST' && $path === '/games') { handleCreateGame($body); return; }
    if ($method === 'POST' && preg_match('#^/games/(\d+)/join$#', $path, $m)) { handleJoinGame((int)$m[1], $body); return; }
    if ($method === 'GET'  && preg_match('#^/games/(\d+)$#', $path, $m)) { handleGetGame((int)$m[1]); return; }
    if ($method === 'POST' && preg_match('#^/games/(\d+)/place$#', $path, $m)) { handlePlaceShips((int)$m[1], $body); return; }
    if ($method === 'POST' && preg_match('#^/games/(\d+)/fire$#', $path, $m)) { handleFire((int)$m[1], $body); return; }
    if ($method === 'GET'  && preg_match('#^/games/(\d+)/moves$#', $path, $m)) { handleGetMoves((int)$m[1]); return; }

    respond(404, ['error' => 'Endpoint not found']);
}

// ===================== HELPERS =====================
function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requireTestMode(): void {
    if (!TEST_MODE) respond(403, ['error' => 'Test mode is disabled']);
    $header = $_SERVER['HTTP_X_TEST_PASSWORD'] ?? '';
    if ($header !== TEST_PASSWORD) respond(403, ['error' => 'Invalid or missing X-Test-Password header']);
}

function requireFields(array $body, array $fields): void {
    foreach ($fields as $f) {
        if (!isset($body[$f]) || $body[$f] === '') {
            respond(400, ['error' => "Missing required field: $f"]);
        }
    }
}

function mapGameStatus(string $status): string {
    $map = ['waiting' => 'waiting_setup', 'active' => 'playing'];
    return $map[$status] ?? $status;
}

// ===================== PRODUCTION HANDLERS =====================

// GET /api/health
function handleHealth(): void {
    respond(200, ['status' => 'ok']);
}

// POST /api/reset
function handleReset(): void {
    $db = getDB();
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    $db->exec("TRUNCATE TABLE api_moves");
    $db->exec("TRUNCATE TABLE api_ships");
    $db->exec("TRUNCATE TABLE api_game_players");
    $db->exec("TRUNCATE TABLE api_games");
    $db->exec("TRUNCATE TABLE api_players");
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    respond(200, ['status' => 'reset']);
}

// POST /api/players  { username }
function handleCreatePlayer(array $body): void {
    requireFields($body, ['username']);
    $username = trim((string)$body['username']);
    if (strlen($username) < 1 || strlen($username) > 50) {
        respond(400, ['error' => 'username must be 1-50 characters']);
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        respond(400, ['error' => 'bad_request']);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM api_players WHERE username = ?");
    $stmt->execute([$username]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) respond(409, ['error' => 'Username already taken']);

    $db->prepare("INSERT INTO api_players (username, games_played, wins, losses, total_shots, total_hits) VALUES (?, 0, 0, 0, 0, 0)")->execute([$username]);
    $id = (int)$db->lastInsertId();
    respond(201, ['player_id' => $id]);
}

// GET /api/players/{id}/stats
function handleGetStats(int $playerId): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, games_played, wins, losses, total_shots, total_hits FROM api_players WHERE id = ?");
    $stmt->execute([$playerId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) respond(404, ['error' => 'Player not found']);

    $shots = (int)$p['total_shots'];
    $hits  = (int)$p['total_hits'];
    respond(200, [
        'games_played' => (int)$p['games_played'],
        'wins'         => (int)$p['wins'],
        'losses'       => (int)$p['losses'],
        'total_shots'  => $shots,
        'total_hits'   => $hits,
        'accuracy'     => $shots > 0 ? round($hits / $shots, 3) : 0.0,
    ]);
}

// POST /api/games  { creator_id, grid_size, max_players }
function handleCreateGame(array $body): void {
    requireFields($body, ['creator_id', 'grid_size', 'max_players']);
    $creatorId  = (int)$body['creator_id'];
    $gridSize   = (int)$body['grid_size'];
    $maxPlayers = (int)$body['max_players'];

    if ($gridSize < 5 || $gridSize > 15) respond(400, ['error' => 'bad_request', 'message' => 'grid_size must be between 5 and 15']);
    if ($maxPlayers < 1)                 respond(400, ['error' => 'max_players must be at least 1']);

    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM api_players WHERE id = ?");
    $stmt->execute([$creatorId]);
    if (!$stmt->fetch()) respond(404, ['error' => 'Creator player not found']);

    $db->prepare("INSERT INTO api_games (creator_id, grid_size, max_players, status, current_turn_index) VALUES (?, ?, ?, 'waiting', 0)")->execute([$creatorId, $gridSize, $maxPlayers]);
    $gameId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO api_game_players (game_id, player_id, turn_order, is_eliminated, ships_placed) VALUES (?, ?, 0, 0, 0)")->execute([$gameId, $creatorId]);

    respond(201, [
        'game_id'     => $gameId,
        'creator_id'  => $creatorId,
        'grid_size'   => $gridSize,
        'max_players' => $maxPlayers,
        'status'      => 'waiting_setup',
    ]);
}

// POST /api/games/{id}/join  { player_id }
function handleJoinGame(int $gameId, array $body): void {
    requireFields($body, ['player_id']);
    $playerId = (int)$body['player_id'];

    $db   = getDB();
    $game = fetchGame($db, $gameId);
    if ($game['status'] !== 'waiting') respond(400, ['error' => 'Game is not open for joining']);

    $stmt = $db->prepare("SELECT id FROM api_players WHERE id = ?");
    $stmt->execute([$playerId]);
    if (!$stmt->fetch()) respond(404, ['error' => 'Player not found']);

    $stmt = $db->prepare("SELECT 1 FROM api_game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if ($stmt->fetch()) respond(400, ['error' => 'Player already in this game']);

    $stmt = $db->prepare("SELECT COUNT(*) FROM api_game_players WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $count = (int)$stmt->fetchColumn();
    if ($count >= (int)$game['max_players']) respond(400, ['error' => 'Game is full']);

    $db->prepare("INSERT INTO api_game_players (game_id, player_id, turn_order, is_eliminated, ships_placed) VALUES (?, ?, ?, 0, 0)")->execute([$gameId, $playerId, $count]);

    respond(200, ['status' => 'joined', 'game_id' => $gameId, 'player_id' => $playerId]);
}

// GET /api/games/{id}
function handleGetGame(int $gameId): void {
    $db   = getDB();
    $game = fetchGame($db, $gameId);

    $stmt = $db->prepare("SELECT COUNT(*) FROM api_game_players WHERE game_id = ? AND is_eliminated = 0");
    $stmt->execute([$gameId]);
    $activePlayers = (int)$stmt->fetchColumn();

    respond(200, [
        'game_id'            => (int)$game['id'],
        'grid_size'          => (int)$game['grid_size'],
        'status'             => mapGameStatus($game['status']),
        'current_turn_index' => (int)$game['current_turn_index'],
        'active_players'     => $activePlayers,
    ]);
}

// POST /api/games/{id}/place  { player_id, ships: [{row, col}, ...] }
function handlePlaceShips(int $gameId, array $body): void {
    requireFields($body, ['player_id', 'ships']);
    $playerId = (int)$body['player_id'];
    $ships    = $body['ships'];

    if (!is_array($ships) || count($ships) !== 3) {
        respond(400, ['error' => 'Exactly 3 ships required']);
    }

    $db       = getDB();
    $game     = fetchGame($db, $gameId);
    $gridSize = (int)$game['grid_size'];
    $gp       = fetchGamePlayer($db, $gameId, $playerId);

    if ($gp['ships_placed']) respond(409, ['error' => 'Ships already placed for this player']);

    $coords = [];
    foreach ($ships as $i => $ship) {
        if (!isset($ship['row'], $ship['col'])) respond(400, ['error' => "Ship $i missing row or col"]);
        $r = (int)$ship['row'];
        $c = (int)$ship['col'];
        if ($r < 0 || $r >= $gridSize || $c < 0 || $c >= $gridSize) {
            respond(400, ['error' => "Ship $i position ($r,$c) out of bounds"]);
        }
        $key = "$r,$c";
        if (isset($coords[$key])) respond(400, ['error' => 'Duplicate ship positions']);
        $coords[$key] = true;
    }

    $stmt = $db->prepare("INSERT INTO api_ships (game_id, player_id, ship_type, row_pos, col_pos, is_hit) VALUES (?, ?, ?, ?, ?, 0)");
    foreach ($ships as $i => $ship) {
        $stmt->execute([$gameId, $playerId, "ship_$i", (int)$ship['row'], (int)$ship['col']]);
    }
    $db->prepare("UPDATE api_game_players SET ships_placed = 1 WHERE game_id = ? AND player_id = ?")->execute([$gameId, $playerId]);

    $stmt = $db->prepare("SELECT COUNT(*) FROM api_game_players WHERE game_id = ? AND ships_placed = 0");
    $stmt->execute([$gameId]);
    if ((int)$stmt->fetchColumn() === 0) {
        $db->prepare("UPDATE api_games SET status = 'active' WHERE id = ?")->execute([$gameId]);
    }

    $stmt = $db->prepare("SELECT status FROM api_games WHERE id = ?");
    $stmt->execute([$gameId]);
    $updatedGame = $stmt->fetch(PDO::FETCH_ASSOC);
    respond(200, [
        'status'      => 'placed',
        'game_id'     => $gameId,
        'player_id'   => $playerId,
        'game_status' => mapGameStatus($updatedGame['status']),
    ]);
}

// POST /api/games/{id}/fire  { player_id, row, col }
function handleFire(int $gameId, array $body): void {
    requireFields($body, ['player_id', 'row', 'col']);
    $playerId = (int)$body['player_id'];
    $row      = (int)$body['row'];
    $col      = (int)$body['col'];

    $db   = getDB();
    $game = fetchGame($db, $gameId);
    if ($game['status'] !== 'active') respond(400, ['error' => 'Game is not active']);

    $gridSize = (int)$game['grid_size'];
    if ($row < 0 || $row >= $gridSize || $col < 0 || $col >= $gridSize) {
        respond(400, ['error' => "Target ($row,$col) is out of bounds"]);
    }

    // Whose turn is it?
    $stmt = $db->prepare("SELECT player_id, turn_order FROM api_game_players WHERE game_id = ? AND is_eliminated = 0 ORDER BY turn_order");
    $stmt->execute([$gameId]);
    $active = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($active)) respond(400, ['error' => 'No active players']);

    $currentIdx    = (int)$game['current_turn_index'];
    $currentPlayer = null;
    foreach ($active as $p) {
        if ((int)$p['turn_order'] === $currentIdx) { $currentPlayer = $p; break; }
    }
    if (!$currentPlayer) $currentPlayer = $active[$currentIdx % count($active)];

    if ((int)$currentPlayer['player_id'] !== $playerId) {
        respond(403, ['error' => 'It is not your turn']);
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM api_moves WHERE game_id = ? AND player_id = ? AND row_pos = ? AND col_pos = ?");
    $stmt->execute([$gameId, $playerId, $row, $col]);
    if ((int)$stmt->fetchColumn() > 0) respond(409, ['error' => 'You already fired at this position']);

    // Check for a hit on any opponent's ship
    $result      = 'miss';
    $hitPlayerId = null;
    foreach ($active as $p) {
        if ((int)$p['player_id'] === $playerId) continue;
        $stmt = $db->prepare("SELECT id FROM api_ships WHERE game_id = ? AND player_id = ? AND row_pos = ? AND col_pos = ? AND is_hit = 0");
        $stmt->execute([$gameId, $p['player_id'], $row, $col]);
        $ship = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($ship) {
            $result      = 'hit';
            $hitPlayerId = (int)$p['player_id'];
            $db->prepare("UPDATE api_ships SET is_hit = 1 WHERE id = ?")->execute([$ship['id']]);
            break;
        }
    }

    // Record the move
    $stmt = $db->prepare("SELECT COUNT(*) FROM api_moves WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $moveNumber = (int)$stmt->fetchColumn() + 1;
    $db->prepare("INSERT INTO api_moves (game_id, player_id, row_pos, col_pos, result, move_number) VALUES (?, ?, ?, ?, ?, ?)")->execute([$gameId, $playerId, $row, $col, $result, $moveNumber]);

    $db->prepare("UPDATE api_players SET total_shots = total_shots + 1 WHERE id = ?")->execute([$playerId]);
    if ($result === 'hit') {
        $db->prepare("UPDATE api_players SET total_hits = total_hits + 1 WHERE id = ?")->execute([$playerId]);
    }

    // Check elimination
    $gameStatus = 'active';
    $winnerId   = null;
    if ($hitPlayerId !== null) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM api_ships WHERE game_id = ? AND player_id = ? AND is_hit = 0");
        $stmt->execute([$gameId, $hitPlayerId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $db->prepare("UPDATE api_game_players SET is_eliminated = 1 WHERE game_id = ? AND player_id = ?")->execute([$gameId, $hitPlayerId]);

            $stmt = $db->prepare("SELECT COUNT(*) FROM api_game_players WHERE game_id = ? AND is_eliminated = 0");
            $stmt->execute([$gameId]);
            if ((int)$stmt->fetchColumn() === 1) {
                $stmt = $db->prepare("SELECT player_id FROM api_game_players WHERE game_id = ? AND is_eliminated = 0 LIMIT 1");
                $stmt->execute([$gameId]);
                $winnerId   = (int)$stmt->fetchColumn();
                $gameStatus = 'finished';

                $db->prepare("UPDATE api_games SET status = 'finished', winner_id = ? WHERE id = ?")->execute([$winnerId, $gameId]);
                $db->prepare("UPDATE api_players SET games_played = games_played + 1, wins = wins + 1 WHERE id = ?")->execute([$winnerId]);

                $stmt = $db->prepare("SELECT player_id FROM api_game_players WHERE game_id = ?");
                $stmt->execute([$gameId]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    if ((int)$pid !== $winnerId) {
                        $db->prepare("UPDATE api_players SET games_played = games_played + 1, losses = losses + 1 WHERE id = ?")->execute([$pid]);
                    }
                }
            }
        }
    }

    // Advance turn if game still active
    $nextPlayerId = null;
    if ($gameStatus === 'active') {
        $stmt = $db->prepare("SELECT turn_order FROM api_game_players WHERE game_id = ? ORDER BY turn_order");
        $stmt->execute([$gameId]);
        $total = count($stmt->fetchAll());

        $nextTurnOrder = $currentIdx;
        for ($i = 1; $i <= $total; $i++) {
            $nextOrder = ($currentIdx + $i) % $total;
            $stmt = $db->prepare("SELECT player_id, is_eliminated FROM api_game_players WHERE game_id = ? AND turn_order = ?");
            $stmt->execute([$gameId, $nextOrder]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($p && !(int)$p['is_eliminated']) {
                $nextTurnOrder = $nextOrder;
                $nextPlayerId  = (int)$p['player_id'];
                break;
            }
        }
        $db->prepare("UPDATE api_games SET current_turn_index = ? WHERE id = ?")->execute([$nextTurnOrder, $gameId]);
    }

    $response = [
        'result'         => $result,
        'next_player_id' => $nextPlayerId,
        'game_status'    => mapGameStatus($gameStatus),
    ];
    if ($winnerId !== null) $response['winner_id'] = $winnerId;
    respond(200, $response);
}

// GET /api/games/{id}/moves
function handleGetMoves(int $gameId): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM api_games WHERE id = ?");
    $stmt->execute([$gameId]);
    if (!$stmt->fetch()) respond(404, ['error' => 'Game not found']);

    $stmt = $db->prepare("SELECT id, player_id, row_pos AS row, col_pos AS col, result, move_number, created_at FROM api_moves WHERE game_id = ? ORDER BY move_number");
    $stmt->execute([$gameId]);
    $moves = array_map(function ($m) {
        return [
            'id'          => (int)$m['id'],
            'player_id'   => (int)$m['player_id'],
            'row'         => (int)$m['row'],
            'col'         => (int)$m['col'],
            'result'      => $m['result'],
            'move_number' => (int)$m['move_number'],
            'created_at'  => $m['created_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    respond(200, ['game_id' => $gameId, 'moves' => $moves]);
}

// ===================== TEST MODE HANDLERS =====================

// POST /api/test/games/{id}/restart
function handleTestRestart(int $gameId): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM api_games WHERE id = ?");
    $stmt->execute([$gameId]);
    if (!$stmt->fetch()) respond(404, ['error' => 'Game not found']);

    $db->prepare("DELETE FROM api_moves WHERE game_id = ?")->execute([$gameId]);
    $db->prepare("DELETE FROM api_ships WHERE game_id = ?")->execute([$gameId]);
    $db->prepare("UPDATE api_game_players SET ships_placed = 0, is_eliminated = 0 WHERE game_id = ?")->execute([$gameId]);
    $db->prepare("UPDATE api_games SET status = 'waiting', current_turn_index = 0, winner_id = NULL WHERE id = ?")->execute([$gameId]);

    respond(200, ['status' => 'reset', 'game_id' => $gameId]);
}

// POST /api/test/games/{id}/ships  { player_id, ships: [{row,col},...] }
function handleTestPlaceShips(int $gameId, array $body): void {
    $playerId = (int)($body['player_id'] ?? $body['playerId'] ?? 0);
    $ships    = $body['ships'] ?? [];

    if (!$playerId) respond(400, ['error' => 'Missing player_id']);
    if (!is_array($ships) || count($ships) !== 3) respond(400, ['error' => 'Exactly 3 ships required']);

    $db = getDB();
    $stmt = $db->prepare("SELECT grid_size FROM api_games WHERE id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$game) respond(404, ['error' => 'Game not found']);

    $gridSize  = (int)$game['grid_size'];
    $allCoords = [];
    foreach ($ships as $i => $ship) {
        $r = (int)($ship['row'] ?? -1);
        $c = (int)($ship['col'] ?? -1);
        if ($r < 0 || $r >= $gridSize || $c < 0 || $c >= $gridSize) {
            respond(400, ['error' => "Ship $i position ($r,$c) out of bounds"]);
        }
        $key = "$r,$c";
        if (isset($allCoords[$key])) respond(400, ['error' => "Overlapping ship at ($r,$c)"]);
        $allCoords[$key] = true;
    }

    $db->prepare("DELETE FROM api_ships WHERE game_id = ? AND player_id = ?")->execute([$gameId, $playerId]);
    $stmt = $db->prepare("INSERT INTO api_ships (game_id, player_id, ship_type, row_pos, col_pos, is_hit) VALUES (?, ?, ?, ?, ?, 0)");
    foreach ($ships as $i => $ship) {
        $stmt->execute([$gameId, $playerId, "ship_$i", (int)$ship['row'], (int)$ship['col']]);
    }
    $db->prepare("UPDATE api_game_players SET ships_placed = 1 WHERE game_id = ? AND player_id = ?")->execute([$gameId, $playerId]);

    $stmt = $db->prepare("SELECT COUNT(*) FROM api_game_players WHERE game_id = ? AND ships_placed = 0");
    $stmt->execute([$gameId]);
    if ((int)$stmt->fetchColumn() === 0) {
        $db->prepare("UPDATE api_games SET status = 'active' WHERE id = ?")->execute([$gameId]);
    }

    respond(200, ['message' => 'Ships placed', 'game_id' => $gameId, 'player_id' => $playerId]);
}

// GET /api/test/games/{id}/board/{player_id}
function handleTestGetBoard(int $gameId, int $playerId): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT grid_size FROM api_games WHERE id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$game) respond(404, ['error' => 'Game not found']);

    $gridSize = (int)$game['grid_size'];
    $grid     = array_fill(0, $gridSize, array_fill(0, $gridSize, 'empty'));

    // Load ships
    $stmt = $db->prepare("SELECT ship_type, row_pos, col_pos, is_hit FROM api_ships WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    $shipRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $shipGroups = [];
    foreach ($shipRows as $s) {
        $type = $s['ship_type'];
        if (!isset($shipGroups[$type])) $shipGroups[$type] = ['cells' => [], 'all_hit' => true];
        $shipGroups[$type]['cells'][] = $s;
        if (!(int)$s['is_hit']) $shipGroups[$type]['all_hit'] = false;
    }

    foreach ($shipRows as $s) {
        $r = (int)$s['row_pos'];
        $c = (int)$s['col_pos'];
        $grid[$r][$c] = (int)$s['is_hit'] ? ($shipGroups[$s['ship_type']]['all_hit'] ? 'sunk' : 'hit') : 'ship';
    }

    // Mark misses
    $stmt = $db->prepare("SELECT DISTINCT row_pos, col_pos FROM api_moves WHERE game_id = ? AND player_id != ?");
    $stmt->execute([$gameId, $playerId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $shot) {
        $r = (int)$shot['row_pos'];
        $c = (int)$shot['col_pos'];
        if ($r >= 0 && $r < $gridSize && $c >= 0 && $c < $gridSize && $grid[$r][$c] === 'empty') {
            $grid[$r][$c] = 'miss';
        }
    }

    respond(200, [
        'game_id'   => $gameId,
        'player_id' => $playerId,
        'grid_size' => $gridSize,
        'board'     => $grid,
    ]);
}

// ===================== DB HELPERS =====================
function fetchGame(PDO $db, int $gameId): array {
    $stmt = $db->prepare("SELECT * FROM api_games WHERE id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$game) respond(404, ['error' => 'Game not found']);
    return $game;
}

function fetchGamePlayer(PDO $db, int $gameId, int $playerId): array {
    $stmt = $db->prepare("SELECT * FROM api_game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    $gp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$gp) respond(403, ['error' => 'Player is not part of this game']);
    return $gp;
}
