<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Battleship Game</title>
    <link rel="stylesheet" href="styles.css?v=2">
</head>
<body>
    <div class="container">
        <h1>⚓ Battleship</h1>

        <div class="game-info">
            <div class="score">
                <p>Shots: <span id="shots">0</span></p>
                <p>Hits: <span id="hits">0</span></p>
                <p>Misses: <span id="misses">0</span></p>
            </div>
            <button id="newGame" class="btn-new-game">New Game</button>
            <button id="debugBtn" class="btn-debug" style="margin-left: 10px;">Debug</button>
        </div>

        <!-- Mode Selection Screen -->
        <div id="modeScreen" class="mode-screen">
            <h2>Choose Game Mode</h2>
            <div class="mode-buttons">
                <button id="modeAI" class="btn btn-mode">vs Computer</button>
                <button id="modePVP" class="btn btn-mode">vs Player</button>
            </div>
        </div>

        <!-- Ship Placement Screen -->
        <div id="placementScreen" class="placement-screen" style="display: none;">
            <h2 id="placementTitle">Place Your Ships</h2>
            <p>Click a ship, then click on your board to place it. Press 'R' to rotate.</p>

            <div class="ship-selection">
                <div class="ship-item" data-ship="carrier" data-size="5">
                    <div class="ship-visual size-5"></div>
                    <span>Carrier (5)</span>
                </div>
                <div class="ship-item" data-ship="cruiser" data-size="3">
                    <div class="ship-visual size-3"></div>
                    <span>Cruiser (3)</span>
                </div>
                <div class="ship-item" data-ship="destroyer" data-size="2">
                    <div class="ship-visual size-2"></div>
                    <span>Destroyer (2)</span>
                </div>
            </div>

            <div class="placement-board-container">
                <h3>Your Board</h3>
                <div id="placementGrid" class="grid-wrapper"></div>
            </div>

            <div class="placement-controls">
                <button id="randomPlace" class="btn">Random Placement</button>
                <button id="startGame" class="btn btn-primary" disabled>Start Game</button>
            </div>
        </div>

        <!-- Game Screen -->
        <div id="gameScreen" class="game-screen" style="display: none;">
            <div class="boards-container">
                <div class="board-section">
                    <h3 id="playerBoardLabel">Your Board</h3>
                    <div id="playerGrid" class="grid-wrapper"></div>
                </div>

                <div class="board-section">
                    <h3 id="enemyBoardLabel">Enemy Board</h3>
                    <div id="computerGrid" class="grid-wrapper"></div>
                </div>
            </div>
        </div>

        <div id="message" class="message"></div>
    </div>

    <!-- Game Over Modal -->
    <div id="gameOverModal" class="modal">
        <div class="modal-content">
            <h2>🎉 Victory! 🎉</h2>
            <p id="winMessage">You found all the ships!</p>
            <div class="stats">
                <h3>This Game</h3>
                <p><strong>Total Shots:</strong> <span id="finalShots">0</span></p>
                <p><strong>Hits:</strong> <span id="finalHits">0</span></p>
                <p><strong>Misses:</strong> <span id="finalMisses">0</span></p>
                <p><strong>Accuracy:</strong> <span id="finalAccuracy">0%</span></p>
            </div>
            <div id="lifetimeStats" class="stats lifetime-stats" style="display: none;">
                <h3>Lifetime Stats</h3>
                <p><strong>Games Played:</strong> <span id="lifetimeTotalGames">0</span></p>
                <p><strong>Wins:</strong> <span id="lifetimeWins">0</span></p>
                <p><strong>Losses:</strong> <span id="lifetimeLosses">0</span></p>
                <p><strong>Best Accuracy:</strong> <span id="lifetimeBestAccuracy">0%</span></p>
                <p><strong>Win Streak:</strong> <span id="lifetimeWinStreak">0</span></p>
                <p><strong>Best Streak:</strong> <span id="lifetimeBestStreak">0</span></p>
            </div>
            <button id="playAgain" class="btn-play-again">Play Again</button>
        </div>
    </div>

    <!-- Handoff Modal -->
    <div id="handoffModal" class="modal">
        <div class="modal-content">
            <h2 id="handoffTitle">Pass to Player 2</h2>
            <p id="handoffMessage">Make sure the other player isn't looking!</p>
            <button id="handoffReady" class="btn-play-again">Ready</button>
        </div>
    </div>

    <script src="script.js?v=2"></script>
</body>
</html>
