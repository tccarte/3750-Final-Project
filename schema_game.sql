-- ===================== GAME TABLES =====================
-- Run this in phpMyAdmin to create the tables used by the Battleship game itself.

CREATE TABLE IF NOT EXISTS players (
    playerId     VARCHAR(36)  PRIMARY KEY,
    displayName  VARCHAR(50)  NOT NULL UNIQUE,
    createdAt    DATETIME     NOT NULL DEFAULT NOW(),
    totalGames   INT          NOT NULL DEFAULT 0,
    totalWins    INT          NOT NULL DEFAULT 0,
    totalLosses  INT          NOT NULL DEFAULT 0,
    totalMoves   INT          NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS games (
    gameId      VARCHAR(36)  PRIMARY KEY,
    gameMode    VARCHAR(10)  NOT NULL DEFAULT 'ai',
    startedAt   DATETIME     NOT NULL DEFAULT NOW(),
    endedAt     DATETIME     DEFAULT NULL,
    winnerId    VARCHAR(36)  DEFAULT NULL,
    FOREIGN KEY (winnerId) REFERENCES players(playerId)
);

CREATE TABLE IF NOT EXISTS game_participants (
    gameId    VARCHAR(36) NOT NULL,
    playerId  VARCHAR(36) NOT NULL,
    shots     INT         NOT NULL DEFAULT 0,
    hits      INT         NOT NULL DEFAULT 0,
    won       TINYINT(1)  NOT NULL DEFAULT 0,
    PRIMARY KEY (gameId, playerId),
    FOREIGN KEY (gameId)   REFERENCES games(gameId),
    FOREIGN KEY (playerId) REFERENCES players(playerId)
);
