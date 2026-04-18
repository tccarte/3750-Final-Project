<?php
require_once __DIR__ . '/../db.php';

define('TEST_MODE', true);
define('TEST_PASSWORD', 'clemson-test-2026');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Test-Password');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Vary: X-Test-Password');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = preg_replace('#^.*/api#', '', $uri);
$path   = '/' . trim($uri, '/');
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    route($method, $path, $body);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

function route(string $method, string $path, array $body): void {
    // Test-mode endpoints
    if ($method === 'POST' && preg_match('#^/test/games/(\d+)/restart$#', $path, $m)) {
        requireTestMode(); handleTestRestart((int)$m[1]); return;
    }
    if ($method === 'POST' && preg_match('#^/test/games/(\d+)/ships$#', $path, $m)) {
        requireTestMode(); handleTestPlaceShips((int)$m[1], $body); return;
    }
    if ($method === 'GET' && preg_match('#^/test/games/(\d+)/board/(\d+)$#', $path, $m)) {
        requireTestMode(); handleTestGetBoard((int)$m[1], (int)$m[2]); return;
    }

    // Production endpoints
    if ($method === 'GET'  && $path === '/health')  { handleHealth(); return; }
    if ($method === 'POST' && $path === '/reset')   { handleReset(); return; }
    if ($method === 'POST' && $path === '/players') { handleCreatePlayer($body); return; }
    if ($method === 'GET'  && $path === '/players') { handleListPlayers(); return; }
    if ($method === 'GET'  && preg_match('#^/players/(\d+)/stats$#', $path, $m)) { handleGetStats((int)$m[1]); return; }
    if ($method === 'GET'  && $path === '/games')   { handleListGames(); return; }
    if ($method === 'POST' && $path === '/games')   { handleCreateGame($body); return; }
    if ($method === 'POST' && preg_match('#^/games/(\d+)/join$#', $path, $m))  { handleJoinGame((int)$m[1], $body); return; }
    if ($method === 'GET'  && preg_match('#^/games/(\d+)$#', $path, $m))       { handleGetGame((int)$m[1]); return; }
    if ($method === 'POST' && preg_match('#^/games/(\d+)/place$#', $path, $m)) { handlePlaceShips((int)$m[1], $body); return; }
    if ($method === 'POST' && preg_match('#^/games/(\d+)/fire$#', $path, $m))  { handleFire((int)$m[1], $body); return; }
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
    if (!TEST_MODE) respond(403, ['error' => 'Invalid or missing X-Test-Password header']);
    $header = $_SERVER['HTTP_X_TEST_PASSWORD'] ?? '';
    if ($header !== TEST_PASSWORD) respond(403, ['error' => 'Invalid or missing X-Test-Password header']);
}

function mapGameStatus(string $dbStatus): string {
    if ($dbStatus === 'waiting') return 'waiting_setup';
    if ($dbStatus === 'active')  return 'playing';
    return $dbStatus; // 'finished' as-is
}

function getBodyPlayerId(array $body): int {
    return (int)($body['player_id'] ?? $body['playerId'] ?? 0);
}

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
    if (!$gp) respond(403, ['error' => 'Player not in this game']);
    return $gp;
}

// ===================== HANDLERS =====================

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

// POST /api/players
function handleCreatePlayer(array $body): void {
    if (!array_key_exists('username', $body)) {
        respond(400, ['error' => 'Missing required field: username']);
    }
    $username = $body['username'];
    if ($username === null || $username === '') {
        respond(400, ['error' => 'Missing required field: username']);
    }
    $username = trim((string)$username);
    if (strlen($username) < 1) {
        respond(400, ['error' => 'Missing required field: username']);
    }
    if (strlen($username) > 30) {
        respond(400, ['error' => 'Username too long']);
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        respond(400, ['error' => 'bad_request']);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM api_players WHERE username = ?");
    $stmt->execute([$username]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        respond(409, ['error' => 'Username already taken']);
    }

    try {
        $db->prepare("INSERT INTO api_players (username, games_played, wins, losses, total_shots, total_hits) VALUES (?, 0, 0, 0, 0, 0)")
           ->execute([$username]);
    } catch (PDOException $e) {
        // Catch UNIQUE constraint violation (race condition safety net)
        if ($e->errorInfo[1] === 1062 || strpos($e->getMessage(), 'Duplicate') !== false) {
            respond(409, ['error' => 'Username already taken']);
        }
        throw $e;
    }
    respond(201, ['player_id' => (int)$db->lastInsertId(), 'username' => $username]);
}

// GET /api/players
function handleListPlayers(): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, games_played, wins, losses, total_shots, total_hits FROM api_players ORDER BY id DESC LIMIT 100");
    $stmt->execute();
    $players = array_map(function($p) {
        $shots = (int)$p['total_shots'];
        $hits  = (int)$p['total_hits'];
        return [
            'player_id'    => (int)$p['id'],
            'username'     => $p['username'],
            'games_played' => (int)$p['games_played'],
            'wins'         => (int)$p['wins'],
            'losses'       => (int)$p['losses'],
            'total_shots'  => $shots,
            'total_hits'   => $hits,
            'accuracy'     => $shots > 0 ? round($hits / $shots, 3) : 0.0,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    respond(200, ['players' => $players]);
}

// GET /api/players/{id}/stats
function handleGetStats(int $playerId): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM api_players WHERE id = ?");
    $stmt->execute([$playerId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) respond(404, ['error' => 'Player not found']);

    $shots = (int)$p['total_shots'];
    $hits  = (int)$p['total_hits'];
    respond(200, [
        'player_id'    => (int)$p['id'],
        'username'     => $p['username'],
        'games_played' => (int)$p['games_played'],
        'wins'         => (int)$p['wins'],
        'losses'       => (int)$p['losses'],
        'total_shots'  => $shots,
        'total_hits'   => $hits,
        'accuracy'     => $shots > 0 ? round($hits / $shots, 3) : 0.0,
    ]);
}

// POST /api/games
function handleCreateGame(array $body): void {
    if (!isset($body['creator_id'])) {
        respond(400, ['error' => 'Missing required field: creator_id']);
    }
    if (!isset($body['grid_size'])) {
        respond(400, ['error' => 'Missing required field: grid_size']);
    }
    if (!isset($body['max_players'])) {
        respond(400, ['error' => 'Missing required field: max_players']);
    }

    $creatorId  = (int)$body['creator_id'];
    $gridSize   = (int)$body['grid_size'];
    $maxPlayers = (int)$body['max_players'];

    if ($creatorId <= 0) {
        respond(400, ['error' => 'Invalid creator_id']);
    }
    if ($gridSize < 5 || $gridSize > 15) {
        respond(400, ['error' => 'grid_size must be between 5 and 15']);
    }
    if ($maxPlayers < 2 || $maxPlayers > 10) {
        respond(400, ['error' => 'max_players must be between 2 and 10']);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM api_players WHERE id = ?");
    $stmt->execute([$creatorId]);
    if (!$stmt->fetch()) respond(400, ['error' => 'Invalid creator_id']);

    $db->prepare("INSERT INTO api_games (creator_id, grid_size, max_players, status, current_turn_index) VALUES (?, ?, ?, 'waiting', 0)")
       ->execute([$creatorId, $gridSize, $maxPlayers]);
    $gameId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO api_game_players (game_id, player_id, turn_order, is_eliminated, ships_placed) VALUES (?, ?, 0, 0, 0)")
       ->execute([$gameId, $creatorId]);

    respond(201, [
        'game_id'     => $gameId,
        'creator_id'  => $creatorId,
        'grid_size'   => $gridSize,
        'max_players' => $maxPlayers,
        'status'      => 'waiting_setup',
    ]);
}

// POST /api/games/{id}/join
function handleJoinGame(int $gameId, array $body): void {
    $playerId = getBodyPlayerId($body);
    if (!$playerId) respond(400, ['error' => 'Missing required field: player_id']);

    $db   = getDB();
    $game = fetchGame($db, $gameId);

    if ($game['status'] !== 'waiting') {
        respond(400, ['error' => 'Game has already started']);
    }

    $stmt = $db->prepare("SELECT id FROM api_players WHERE id = ?");
    $stmt->execute([$playerId]);
    if (!$stmt->fetch()) respond(404, ['error' => 'Player not found']);

    // Check if player already in game
    $stmt = $db->prepare("SELECT 1 FROM api_game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if ($stmt->fetch()) {
        respond(400, ['error' => 'Player already in this game']);
    }

    // Check capacity
    $stmt = $db->prepare("SELECT COUNT(*) FROM api_game_players WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $count = (int)$stmt->fetchColumn();
    if ($count >= (int)$game['max_players']) {
        respond(400, ['error' => 'Game is full']);
    }

    $db->prepare("INSERT INTO api_game_players (game_id, player_id, turn_order, is_eliminated, ships_placed) VALUES (?, ?, ?, 0, 0)")
       ->execute([$gameId, $playerId, $count]);

    respond(200, ['status' => 'joined', 'game_id' => $gameId, 'player_id' => $playerId]);
}

// GET /api/games
function handleListGames(): void {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT g.id, g.grid_size, g.max_players, g.status, g.creator_id,
               p.username AS creator_name,
               COUNT(gp.player_id) AS player_count
        FROM api_games g
        LEFT JOIN api_players p ON g.creator_id = p.id
        LEFT JOIN api_game_players gp ON g.id = gp.game_id
        WHERE g.status = 'waiting'
        GROUP BY g.id
        ORDER BY g.id DESC
        LIMIT 20
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $games = array_map(function($g) {
        return [
            'game_id'      => (int)$g['id'],
            'grid_size'    => (int)$g['grid_size'],
            'max_players'  => (int)$g['max_players'],
            'status'       => 'waiting_setup',
            'creator_name' => $g['creator_name'] ?? 'Unknown',
            'player_count' => (int)$g['player_count'],
        ];
    }, $rows);
    respond(200, ['games' => $games]);
}

// GET /api/games/{id}
function handleGetGame(int $gameId): void {
    $db   = getDB();
    $game = fetchGame($db, $gameId);

    // Fetch players list for the response
    $stmt = $db->prepare(
        "SELECT player_id, turn_order, is_eliminated, ships_placed
         FROM api_game_players WHERE game_id = ? ORDER BY turn_order"
    );
    $stmt->execute([$gameId]);
    $players = array_map(function($p) {
        return [
            'player_id'     => (int)$p['player_id'],
            'turn_order'    => (int)$p['turn_order'],
            'is_eliminated' => (bool)$p['is_eliminated'],
            'ships_placed'  => (bool)$p['ships_placed'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Count total moves in this game
    $stmt = $db->prepare("SELECT COUNT(*) FROM api_moves WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $totalMoves = (int)$stmt->fetchColumn();

    // current_turn_player_id is null before game is playing
    $currentTurnPlayerId = null;
    if ($game['status'] === 'active') {
        $stmt = $db->prepare(
            "SELECT player_id FROM api_game_players
             WHERE game_id = ? AND turn_order = ? AND is_eliminated = 0"
        );
        $stmt->execute([$gameId, (int)$game['current_turn_index']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $currentTurnPlayerId = (int)$row['player_id'];
    }

    respond(200, [
        'game_id'                => (int)$game['id'],
        'grid_size'              => (int)$game['grid_size'],
        'max_players'            => (int)$game['max_players'],
        'status'                 => mapGameStatus($game['status']),
        'players'                => $players,
        'current_turn_player_id' => $currentTurnPlayerId,
        'current_turn_index'     => (int)$game['current_turn_index'],
        'winner_id'              => $game['winner_id'] !== null ? (int)$game['winner_id'] : null,
        'total_moves'            => $totalMoves,
    ]);
}

// POST /api/games/{id}/place
function handlePlaceShips(int $gameId, array $body): void {
    $playerId = getBodyPlayerId($body);
    $ships    = $body['ships'] ?? null;

    if (!$playerId) respond(400, ['error' => 'Missing required field: player_id']);
    if (!is_array($ships) || count($ships) < 3) {
        respond(400, ['error' => 'At least 3 ship cells required']);
    }

    $db       = getDB();
    $game     = fetchGame($db, $gameId);
    $gridSize = (int)$game['grid_size'];
    $gp       = fetchGamePlayer($db, $gameId, $playerId);

    if ((int)$gp['ships_placed']) {
        respond(409, ['error' => 'Ships already placed for this player']);
    }

    $coords = [];
    foreach ($ships as $ship) {
        if (!isset($ship['row'], $ship['col'])) respond(400, ['error' => 'Missing ship coordinates']);
        $r = (int)$ship['row'];
        $c = (int)$ship['col'];
        if ($r < 0 || $r >= $gridSize || $c < 0 || $c >= $gridSize) {
            respond(400, ['error' => 'Ship position out of bounds']);
        }
        $key = "$r,$c";
        if (isset($coords[$key])) respond(400, ['error' => 'Duplicate ship positions']);
        $coords[$key] = true;
    }

    $stmt = $db->prepare("INSERT INTO api_ships (game_id, player_id, ship_type, row_pos, col_pos, is_hit) VALUES (?, ?, ?, ?, ?, 0)");
    foreach ($ships as $i => $ship) {
        $stmt->execute([$gameId, $playerId, "ship_$i", (int)$ship['row'], (int)$ship['col']]);
    }

    $db->prepare("UPDATE api_game_players SET ships_placed = 1 WHERE game_id = ? AND player_id = ?")
       ->execute([$gameId, $playerId]);

    $stmt = $db->prepare("SELECT COUNT(*) FROM api_game_players WHERE game_id = ? AND ships_placed = 0");
    $stmt->execute([$gameId]);
    if ((int)$stmt->fetchColumn() === 0) {
        $db->prepare("UPDATE api_games SET status = 'active' WHERE id = ?")->execute([$gameId]);
    }

    $stmt = $db->prepare("SELECT status FROM api_games WHERE id = ?");
    $stmt->execute([$gameId]);
    $updatedStatus = $stmt->fetch(PDO::FETCH_ASSOC)['status'];

    respond(200, [
        'status'      => 'placed',
        'game_id'     => $gameId,
        'player_id'   => $playerId,
        'game_status' => mapGameStatus($updatedStatus),
    ]);
}

// POST /api/games/{id}/fire
function handleFire(int $gameId, array $body): void {
    $playerId = getBodyPlayerId($body);
    $row      = $body['row'] ?? null;
    $col      = $body['col'] ?? null;

    if (!$playerId || $row === null || $col === null) {
        respond(400, ['error' => 'Missing required fields']);
    }
    $row = (int)$row;
    $col = (int)$col;

    $db   = getDB();
    $game = fetchGame($db, $gameId);

    if ($game['status'] !== 'active') {
        respond(400, ['error' => 'Game is not active']);
    }

    $gridSize = (int)$game['grid_size'];
    if ($row < 0 || $row >= $gridSize || $col < 0 || $col >= $gridSize) {
        respond(400, ['error' => 'Position out of bounds']);
    }

    // Turn enforcement
    $stmt = $db->prepare("SELECT player_id, turn_order FROM api_game_players WHERE game_id = ? AND is_eliminated = 0 ORDER BY turn_order");
    $stmt->execute([$gameId]);
    $active = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($active)) respond(400, ['error' => 'No active players']);

    $currentIdx    = (int)$game['current_turn_index'];
    $currentPlayer = null;
    foreach ($active as $p) {
        if ((int)$p['turn_order'] === $currentIdx) { $currentPlayer = $p; break; }
    }
    if (!$currentPlayer) {
        $currentPlayer = $active[$currentIdx % count($active)];
    }

    if ((int)$currentPlayer['player_id'] !== $playerId) {
        respond(403, ['error' => 'It is not your turn']);
    }

    // Duplicate fire check
    $stmt = $db->prepare("SELECT COUNT(*) FROM api_moves WHERE game_id = ? AND player_id = ? AND row_pos = ? AND col_pos = ?");
    $stmt->execute([$gameId, $playerId, $row, $col]);
    if ((int)$stmt->fetchColumn() > 0) {
        respond(409, ['error' => 'You already fired at this position']);
    }

    // Check hit against all other players' ships
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

    // Record move
    $stmt = $db->prepare("SELECT COUNT(*) FROM api_moves WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $moveNumber = (int)$stmt->fetchColumn() + 1;
    $db->prepare("INSERT INTO api_moves (game_id, player_id, row_pos, col_pos, result, move_number) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$gameId, $playerId, $row, $col, $result, $moveNumber]);

    // Update player stats
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
            $db->prepare("UPDATE api_game_players SET is_eliminated = 1 WHERE game_id = ? AND player_id = ?")
               ->execute([$gameId, $hitPlayerId]);

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

    // Advance turn
    $nextPlayerId = null;
    if ($gameStatus === 'active') {
        $stmt = $db->prepare("SELECT COUNT(*) FROM api_game_players WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $totalPlayers = (int)$stmt->fetchColumn();

        $nextTurnOrder = $currentIdx;
        for ($i = 1; $i <= $totalPlayers; $i++) {
            $nextOrder = ($currentIdx + $i) % $totalPlayers;
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
    fetchGame($db, $gameId);

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

// POST /api/test/games/{id}/ships
function handleTestPlaceShips(int $gameId, array $body): void {
    $playerId = getBodyPlayerId($body);
    $ships    = $body['ships'] ?? [];

    if (!$playerId) respond(400, ['error' => 'Missing required field: player_id']);
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
            respond(400, ['error' => "Ship $i position out of bounds"]);
        }
        $key = "$r,$c";
        if (isset($allCoords[$key])) respond(400, ['error' => "Overlapping ship positions"]);
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
        $grid[$r][$c] = (int)$s['is_hit']
            ? ($shipGroups[$s['ship_type']]['all_hit'] ? 'sunk' : 'hit')
            : 'ship';
    }

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
