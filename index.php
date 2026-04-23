<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Battleship</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=5">
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
            <button id="themeToggle" class="btn-theme-toggle" title="Toggle light/dark mode">☀️</button>
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
                <div class="name-input-group">
                    <label for="playerName">CALL SIGN</label>
                    <input type="text" id="playerName" placeholder="Enter your name" maxlength="50" autocomplete="off">
                </div>
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
                    <button id="modeOnline" class="btn btn-mode">
                        <span class="mode-icon">🌐</span>
                        <span class="mode-label">Online PvP</span>
                        <span class="mode-detail">Play over Internet</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Online Lobby Screen -->
        <div id="onlineLobby" style="display:none;">
            <div class="lobby-layout">
                <div class="lobby-sidebar">
                    <h2 class="lobby-heading">ONLINE BATTLE</h2>
                    <p class="lobby-subheading">Create or join a live game</p>
                    <div class="lobby-create-form">
                        <label class="lobby-label">GRID SIZE</label>
                        <select id="gridSizeSelect" class="lobby-select">
                            <option value="8">8 × 8</option>
                            <option value="10" selected>10 × 10 (default)</option>
                            <option value="12">12 × 12</option>
                            <option value="15">15 × 15</option>
                        </select>
                        <button id="onlineCreate" class="btn btn-primary lobby-create-btn">+ Create Game</button>
                    </div>
                    <div id="onlineMessage" class="lobby-message"></div>
                    <button id="onlineBack" class="btn btn-secondary lobby-back-btn">← Back</button>
                </div>
                <div class="lobby-games-panel">
                    <div class="lobby-games-header">
                        <span class="lobby-games-title">OPEN GAMES</span>
                        <span class="lobby-live">● LIVE</span>
                    </div>
                    <div id="gamesList" class="games-list">
                        <div class="games-empty">Loading games...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Waiting Screen -->
        <div id="waitingScreen" style="display:none;">
            <div class="mode-card">
                <h2 id="waitingTitle">WAITING...</h2>
                <p id="waitingMessage">Waiting for opponent...</p>
                <div id="shareGameIdBox" style="display:none; margin-top:1rem;">
                    <p>Share this Game ID with your opponent:</p>
                    <div class="game-id-display" id="shareGameId" style="font-size:2rem; font-weight:bold; letter-spacing:0.2em; margin-top:0.5rem;"></div>
                </div>
            </div>
        </div>

        <!-- Ship Placement Screen -->
        <div id="placementScreen" class="placement-screen" style="display: none;">
            <div class="placement-header">
                <h2 id="placementTitle">Deploy Your Fleet</h2>
                <p id="placementSubtitle">Select a ship below, then click on the grid. Press <kbd>R</kbd> to rotate.</p>
            </div>

            <!-- Online placement info (shown in online mode) -->
            <div id="onlineShipSelection" style="display:none; text-align:center; margin-bottom:1rem;">
                <p>Click <strong>3 cells</strong> on the grid to place your ships. Click again to remove.</p>
                <div id="onlineShipCount" style="font-size:1.2rem; font-weight:bold; margin-top:0.5rem;">Ships placed: 0 / 3</div>
                <div id="onlineGameIdDisplay" style="display:none; margin-top:0.5rem; color:#aaa;"></div>
            </div>

            <div class="ship-selection">
                <div class="ship-item" data-ship="carrier" data-size="5">
                    <span class="ship-emoji">🚢</span>
                    <span class="ship-name">Carrier</span>
                    <span class="ship-size">5</span>
                </div>
                <div class="ship-item" data-ship="battleship" data-size="4">
                    <span class="ship-emoji">⛴️</span>
                    <span class="ship-name">Battleship</span>
                    <span class="ship-size">4</span>
                </div>
                <div class="ship-item" data-ship="cruiser" data-size="3">
                    <span class="ship-emoji">🛳️</span>
                    <span class="ship-name">Cruiser</span>
                    <span class="ship-size">3</span>
                </div>
                <div class="ship-item" data-ship="submarine" data-size="3">
                    <span class="ship-emoji">🛟</span>
                    <span class="ship-name">Submarine</span>
                    <span class="ship-size">3</span>
                </div>
                <div class="ship-item" data-ship="destroyer" data-size="2">
                    <span class="ship-emoji">⚓</span>
                    <span class="ship-name">Destroyer</span>
                    <span class="ship-size">2</span>
                </div>
            </div>

            <div class="placement-board-container">
                <h3>Your Board</h3>
                <div id="placementGrid" class="grid-wrapper"></div>
            </div>

            <div class="placement-controls">
                <button id="randomPlace" class="btn btn-secondary">⚄ Random</button>
                <button id="startGame" class="btn btn-primary" disabled>Start Game →</button>
                <button id="onlineConfirmPlacement" class="btn btn-primary" style="display:none;" disabled>Confirm Placement</button>
            </div>
        </div>

        <!-- Game Screen -->
        <div id="gameScreen" class="game-screen" style="display: none;">
            <div id="turnIndicator" class="turn-indicator" style="display:none;"></div>
            <div class="boards-container">
                <div class="board-column">
                    <div class="board-section your-board">
                        <div class="board-label-bar">
                            <span class="board-dot friendly"></span>
                            <h3 id="playerBoardLabel">Your Board</h3>
                        </div>
                        <div id="playerGrid" class="grid-wrapper"></div>
                    </div>
                    <div class="ship-tracker" id="playerTracker">
                        <div class="tracker-header">
                            <span class="tracker-title">Your Fleet</span>
                            <span class="tracker-count" id="playerShipsLeft">5 remaining</span>
                        </div>
                        <div class="tracker-list" id="playerShipList"></div>
                    </div>
                </div>

                <div class="board-divider">
                    <span class="vs-badge">VS</span>
                </div>

                <div class="board-column">
                    <div class="board-section enemy-board">
                        <div class="board-label-bar">
                            <span class="board-dot hostile"></span>
                            <h3 id="enemyBoardLabel">Enemy Board</h3>
                        </div>
                        <div id="computerGrid" class="grid-wrapper"></div>
                    </div>
                    <div class="ship-tracker" id="enemyTracker">
                        <div class="tracker-header">
                            <span class="tracker-title">Enemy Fleet</span>
                            <span class="tracker-count" id="enemyShipsLeft">5 remaining</span>
                        </div>
                        <div class="tracker-list" id="enemyShipList"></div>
                    </div>
                </div>
            </div>
            <div id="moveHistoryPanel" class="move-history-panel" style="display:none;">
                <div class="move-history-header">
                    <span class="mh-title">MOVE LOG</span>
                    <span id="moveCount" class="mh-count">0 moves</span>
                </div>
                <div id="moveHistoryList" class="move-history-list"></div>
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

    <script src="script.js?v=6"></script>
</body>
</html>