<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Battleship</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=3">
</head>
<body>
    <div class="scanline-overlay"></div>

    <div class="container">
        <header class="game-header">
            <div class="logo">
                <span class="logo-icon">⚓</span>
                <h1>BATTLESHIP</h1>
                <span class="logo-subtitle">NAVAL COMMAND</span>
            </div>
        </header>

        <div class="game-info">
            <div class="score">
                <div class="stat-block">
                    <span class="stat-label">SHOTS</span>
                    <span class="stat-value" id="shots">0</span>
                </div>
                <div class="stat-block">
                    <span class="stat-label">HITS</span>
                    <span class="stat-value hit-color" id="hits">0</span>
                </div>
                <div class="stat-block">
                    <span class="stat-label">MISS</span>
                    <span class="stat-value miss-color" id="misses">0</span>
                </div>
            </div>
            <div class="game-controls">
                <button id="newGame" class="btn-new-game">
                    <span class="btn-icon">↻</span> New Game
                </button>
                <button id="debugBtn" class="btn-debug">DBG</button>
            </div>
        </div>

        <!-- Mode Selection Screen -->
        <div id="modeScreen" class="mode-screen">
            <div class="mode-card">
                <h2>SELECT OPERATION</h2>
                <p class="mode-desc">Choose your engagement type</p>
                <div class="mode-buttons">
                    <button id="modeAI" class="btn btn-mode">
                        <span class="mode-icon">🤖</span>
                        <span class="mode-label">vs Computer</span>
                        <span class="mode-detail">Hunt/Target AI</span>
                    </button>
                    <button id="modePVP" class="btn btn-mode">
                        <span class="mode-icon">👥</span>
                        <span class="mode-label">vs Player</span>
                        <span class="mode-detail">Local 2-Player</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Ship Placement Screen -->
        <div id="placementScreen" class="placement-screen" style="display: none;">
            <div class="placement-header">
                <h2 id="placementTitle">Place Your Ships</h2>
                <p>Select a ship below, then click on the grid. Press <kbd>R</kbd> to rotate.</p>
            </div>

            <div class="ship-selection">
                <div class="ship-item" data-ship="carrier" data-size="5">
                    <div class="ship-visual size-5"></div>
                    <span class="ship-name">Carrier</span>
                    <span class="ship-size">5 cells</span>
                </div>
                <div class="ship-item" data-ship="cruiser" data-size="3">
                    <div class="ship-visual size-3"></div>
                    <span class="ship-name">Cruiser</span>
                    <span class="ship-size">3 cells</span>
                </div>
                <div class="ship-item" data-ship="destroyer" data-size="2">
                    <div class="ship-visual size-2"></div>
                    <span class="ship-name">Destroyer</span>
                    <span class="ship-size">2 cells</span>
                </div>
            </div>

            <div class="placement-board-container">
                <h3>Your Board</h3>
                <div id="placementGrid" class="grid-wrapper"></div>
            </div>

            <div class="placement-controls">
                <button id="randomPlace" class="btn btn-secondary">⚄ Random</button>
                <button id="startGame" class="btn btn-primary" disabled>Start Game →</button>
            </div>
        </div>

        <!-- Game Screen -->
        <div id="gameScreen" class="game-screen" style="display: none;">
            <div class="boards-container">
                <div class="board-section your-board">
                    <div class="board-label-bar">
                        <span class="board-dot friendly"></span>
                        <h3 id="playerBoardLabel">Your Board</h3>
                    </div>
                    <div id="playerGrid" class="grid-wrapper"></div>
                </div>

                <div class="board-divider">
                    <span class="vs-badge">VS</span>
                </div>

                <div class="board-section enemy-board">
                    <div class="board-label-bar">
                        <span class="board-dot hostile"></span>
                        <h3 id="enemyBoardLabel">Enemy Board</h3>
                    </div>
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
            <div class="stats-grid">
                <div class="stats">
                    <h3>Mission Report</h3>
                    <div class="stats-row">
                        <div class="stat-item">
                            <span class="stat-num" id="finalShots">0</span>
                            <span class="stat-desc">Shots</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-num" id="finalHits">0</span>
                            <span class="stat-desc">Hits</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-num" id="finalMisses">0</span>
                            <span class="stat-desc">Misses</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-num" id="finalAccuracy">0%</span>
                            <span class="stat-desc">Accuracy</span>
                        </div>
                    </div>
                </div>
                <div id="lifetimeStats" class="stats lifetime-stats" style="display: none;">
                    <h3>Career Record</h3>
                    <div class="stats-row">
                        <div class="stat-item">
                            <span class="stat-num" id="lifetimeTotalGames">0</span>
                            <span class="stat-desc">Games</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-num" id="lifetimeWins">0</span>
                            <span class="stat-desc">Wins</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-num" id="lifetimeLosses">0</span>
                            <span class="stat-desc">Losses</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-num" id="lifetimeBestAccuracy">0%</span>
                            <span class="stat-desc">Best Acc.</span>
                        </div>
                    </div>
                    <div class="stats-row streaks">
                        <div class="stat-item">
                            <span class="stat-num" id="lifetimeWinStreak">0</span>
                            <span class="stat-desc">Streak</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-num" id="lifetimeBestStreak">0</span>
                            <span class="stat-desc">Best Streak</span>
                        </div>
                    </div>
                </div>
            </div>
            <button id="playAgain" class="btn-play-again">Play Again</button>
        </div>
    </div>

    <!-- Handoff Modal -->
    <div id="handoffModal" class="modal">
        <div class="modal-content handoff-content">
            <h2 id="handoffTitle">Pass to Player 2</h2>
            <p id="handoffMessage">Make sure the other player isn't looking!</p>
            <button id="handoffReady" class="btn-play-again">Ready</button>
        </div>
    </div>

    <script src="script.js?v=2"></script>
</body>
</html>