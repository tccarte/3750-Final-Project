-- ===================== API TABLES =====================
-- Run this in phpMyAdmin to create all tables needed for the grading API.

CREATE TABLE IF NOT EXISTS api_players (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(50) NOT NULL UNIQUE,
    games_played INT NOT NULL DEFAULT 0,
    wins        INT NOT NULL DEFAULT 0,
    losses      INT NOT NULL DEFAULT 0,
    total_shots INT NOT NULL DEFAULT 0,
    total_hits  INT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS api_games (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    creator_id          INT NOT NULL,
    grid_size           INT NOT NULL DEFAULT 10,
    max_players         INT NOT NULL DEFAULT 2,
    status              ENUM('waiting', 'active', 'finished') NOT NULL DEFAULT 'waiting',
    current_turn_index  INT NOT NULL DEFAULT 0,
    winner_id           INT DEFAULT NULL,
    FOREIGN KEY (creator_id) REFERENCES api_players(id),
    FOREIGN KEY (winner_id)  REFERENCES api_players(id)
);

CREATE TABLE IF NOT EXISTS api_game_players (
    game_id       INT NOT NULL,
    player_id     INT NOT NULL,
    turn_order    INT NOT NULL DEFAULT 0,
    is_eliminated TINYINT(1) NOT NULL DEFAULT 0,
    ships_placed  TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (game_id, player_id),
    FOREIGN KEY (game_id)   REFERENCES api_games(id),
    FOREIGN KEY (player_id) REFERENCES api_players(id)
);

CREATE TABLE IF NOT EXISTS api_ships (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    game_id     INT NOT NULL,
    player_id   INT NOT NULL,
    ship_type   VARCHAR(50) NOT NULL DEFAULT 'ship',
    row_pos     INT NOT NULL,
    col_pos     INT NOT NULL,
    is_hit      TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY unique_cell (game_id, player_id, row_pos, col_pos),
    FOREIGN KEY (game_id)   REFERENCES api_games(id),
    FOREIGN KEY (player_id) REFERENCES api_players(id)
);

CREATE TABLE IF NOT EXISTS api_moves (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    game_id     INT NOT NULL,
    player_id   INT NOT NULL,
    row_pos     INT NOT NULL,
    col_pos     INT NOT NULL,
    result      ENUM('hit', 'miss') NOT NULL,
    move_number INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (game_id)   REFERENCES api_games(id),
    FOREIGN KEY (player_id) REFERENCES api_players(id)
);
