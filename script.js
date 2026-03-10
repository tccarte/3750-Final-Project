// Game state
let gameState = 'mode_select'; // 'mode_select', 'placement', 'playing', 'gameover'
let gameActive = false;
let gameMode = 'ai'; // 'ai' or 'pvp'
let shots = 0;
let hits = 0;
let misses = 0;

// PvP state
let currentPlayer = 1;
let pvpPlacementPhase = 1; // 1 = P1 placing, 2 = P2 placing
let p1PlacedShips = [];
let p2PlacedShips = [];
let p1AttackHits = [];
let p1AttackMisses = [];
let p2AttackHits = [];
let p2AttackMisses = [];
let p1Shots = 0, p1Hits = 0, p1Misses = 0;
let p2Shots = 0, p2Hits = 0, p2Misses = 0;

// Ship placement state
let selectedShip = null;
let shipOrientation = 'H'; // 'H' or 'V'
let playerShips = [];
let placedShips = [];

// Ships to place
const shipsToPlace = [
    { type: 'carrier', size: 5 },
    { type: 'cruiser', size: 3 },
    { type: 'destroyer', size: 2 }
];

// Initialize game
document.addEventListener('DOMContentLoaded', () => {
    if (!restoreState()) {
        showModeSelection();
    }

    document.getElementById('modeAI').addEventListener('click', () => {
        gameMode = 'ai';
        document.getElementById('modeScreen').style.display = 'none';
        initPlacementPhase();
    });

    document.getElementById('modePVP').addEventListener('click', () => {
        gameMode = 'pvp';
        pvpPlacementPhase = 1;
        document.getElementById('modeScreen').style.display = 'none';
        initPlacementPhase();
    });

    document.getElementById('newGame').addEventListener('click', () => {
        resetGame();
    });

    document.getElementById('randomPlace').addEventListener('click', () => {
        randomPlaceShips();
    });

    document.getElementById('startGame').addEventListener('click', () => {
        handleStartGameClick();
    });

    document.getElementById('playAgain').addEventListener('click', () => {
        hideGameOverModal();
        resetGame();
    });

    document.getElementById('debugBtn').addEventListener('click', async () => {
        try {
            const response = await fetch('game.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'debug' })
            });
            const data = await response.json();
            console.log('=== GAME DEBUG INFO ===');
            console.log('State:', gameState);
            console.log('Player Ships:', placedShips);
            console.log('Server Data:', data);
            alert('Debug info logged to console (F12)');
        } catch (error) {
            console.error('Debug error:', error);
        }
    });

    // Keyboard controls
    document.addEventListener('keydown', (e) => {
        if (gameState === 'placement' && (e.key === 'r' || e.key === 'R')) {
            shipOrientation = shipOrientation === 'H' ? 'V' : 'H';
            showMessage(`Orientation: ${shipOrientation === 'H' ? 'Horizontal' : 'Vertical'}`);
        }
    });
});

// Show mode selection screen
function showModeSelection() {
    gameState = 'mode_select';
    document.getElementById('modeScreen').style.display = 'block';
    document.getElementById('placementScreen').style.display = 'none';
    document.getElementById('gameScreen').style.display = 'none';
    showMessage('Choose your game mode');
}

// Initialize placement phase
function initPlacementPhase() {
    gameState = 'placement';
    placedShips = [];
    document.getElementById('modeScreen').style.display = 'none';
    document.getElementById('placementScreen').style.display = 'block';
    document.getElementById('gameScreen').style.display = 'none';
    document.getElementById('startGame').disabled = true;

    if (gameMode === 'pvp') {
        const playerLabel = pvpPlacementPhase === 1 ? 'Player 1' : 'Player 2';
        document.getElementById('placementTitle').textContent = playerLabel + ' - Place Your Ships';
        document.getElementById('startGame').textContent = pvpPlacementPhase === 1 ? 'Confirm Ships' : 'Start Game';
    } else {
        document.getElementById('placementTitle').textContent = 'Place Your Ships';
        document.getElementById('startGame').textContent = 'Start Game';
    }

    createPlacementGrid();
    setupShipSelection();
    showMessage('Select a ship and place it on your board');
}

// Create placement grid
function createPlacementGrid() {
    const container = document.getElementById('placementGrid');
    container.innerHTML = '';

    const grid = createGridElement('placement');
    container.appendChild(grid);
}

// Create a grid element
function createGridElement(gridId) {
    const wrapper = document.createElement('div');
    const rows = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];

    // Header
    const header = document.createElement('div');
    header.className = 'grid-header';
    header.innerHTML = '<div class="corner"></div>';
    for (let i = 1; i <= 10; i++) {
        header.innerHTML += `<div class="col-label">${i}</div>`;
    }
    wrapper.appendChild(header);

    // Grid
    const grid = document.createElement('div');
    grid.className = 'grid';
    grid.id = gridId + 'Cells';

    for (let i = 0; i < 10; i++) {
        // Row label
        const rowLabel = document.createElement('div');
        rowLabel.className = 'row-label';
        rowLabel.textContent = rows[i];
        grid.appendChild(rowLabel);

        // Cells
        for (let j = 0; j < 10; j++) {
            const cell = document.createElement('div');
            cell.className = 'cell';
            cell.dataset.row = i;
            cell.dataset.col = j;
            cell.dataset.coord = rows[i] + (j + 1);

            if (gridId === 'placement') {
                cell.addEventListener('click', () => handlePlacementClick(i, j));
                cell.addEventListener('mouseenter', () => showPlacementPreview(i, j));
                cell.addEventListener('mouseleave', () => clearPlacementPreview());
            } else if (gridId === 'computer') {
                cell.addEventListener('click', () => handlePlayerShot(i, j, cell));
            }

            grid.appendChild(cell);
        }
    }

    wrapper.appendChild(grid);
    return wrapper;
}

// Setup ship selection
function setupShipSelection() {
    document.querySelectorAll('.ship-item').forEach(item => {
        item.classList.remove('selected', 'placed');
        item.addEventListener('click', () => {
            if (!item.classList.contains('placed')) {
                document.querySelectorAll('.ship-item').forEach(i => i.classList.remove('selected'));
                item.classList.add('selected');
                selectedShip = {
                    type: item.dataset.ship,
                    size: parseInt(item.dataset.size)
                };
                showMessage(`Selected ${selectedShip.type} (size ${selectedShip.size}). Click to place, press R to rotate.`);
            }
        });
    });
}

// Show placement preview
function showPlacementPreview(row, col) {
    if (!selectedShip) return;

    clearPlacementPreview();

    const cells = [];
    const size = selectedShip.size;
    let valid = true;

    if (shipOrientation === 'H') {
        if (col + size > 10) valid = false;
        for (let i = 0; i < size && valid; i++) {
            const cell = getCellElement('placementCells', row, col + i);
            if (cell.classList.contains('ship')) valid = false;
            cells.push(cell);
        }
    } else {
        if (row + size > 10) valid = false;
        for (let i = 0; i < size && valid; i++) {
            const cell = getCellElement('placementCells', row + i, col);
            if (cell.classList.contains('ship')) valid = false;
            cells.push(cell);
        }
    }

    cells.forEach(cell => {
        if (cell) cell.classList.add(valid ? 'valid-placement' : 'invalid-placement');
    });
}

// Clear placement preview
function clearPlacementPreview() {
    document.querySelectorAll('.valid-placement, .invalid-placement').forEach(cell => {
        cell.classList.remove('valid-placement', 'invalid-placement');
    });
}

// Handle placement click
function handlePlacementClick(row, col) {
    if (!selectedShip) {
        showMessage('Select a ship first!');
        return;
    }

    if (canPlaceShip(row, col, selectedShip.size, shipOrientation)) {
        placeShip(row, col, selectedShip, shipOrientation);

        // Mark ship as placed
        document.querySelector(`[data-ship="${selectedShip.type}"]`).classList.add('placed');

        placedShips.push({
            type: selectedShip.type,
            size: selectedShip.size,
            row: row,
            col: col,
            orientation: shipOrientation,
            positions: getShipPositions(row, col, selectedShip.size, shipOrientation)
        });

        selectedShip = null;
        clearPlacementPreview();

        // Check if all ships placed
        if (placedShips.length === shipsToPlace.length) {
            document.getElementById('startGame').disabled = false;
            const btnLabel = document.getElementById('startGame').textContent;
            showMessage('All ships placed! Click "' + btnLabel + '" to continue.');
        } else {
            showMessage('Ship placed! Select next ship.');
        }
        saveState();
    } else {
        showMessage('Cannot place ship here!');
    }
}

// Can place ship
function canPlaceShip(row, col, size, orientation) {
    if (orientation === 'H') {
        if (col + size > 10) return false;
        for (let i = 0; i < size; i++) {
            const cell = getCellElement('placementCells', row, col + i);
            if (cell.classList.contains('ship')) return false;
        }
    } else {
        if (row + size > 10) return false;
        for (let i = 0; i < size; i++) {
            const cell = getCellElement('placementCells', row + i, col);
            if (cell.classList.contains('ship')) return false;
        }
    }
    return true;
}

// Place ship visually
function placeShip(row, col, ship, orientation) {
    const size = ship.size;

    if (orientation === 'H') {
        for (let i = 0; i < size; i++) {
            const cell = getCellElement('placementCells', row, col + i);
            cell.classList.add('ship');
        }
    } else {
        for (let i = 0; i < size; i++) {
            const cell = getCellElement('placementCells', row + i, col);
            cell.classList.add('ship');
        }
    }
}

// Get ship positions
function getShipPositions(row, col, size, orientation) {
    const rows = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
    const positions = [];

    if (orientation === 'H') {
        for (let i = 0; i < size; i++) {
            positions.push(rows[row] + (col + i + 1));
        }
    } else {
        for (let i = 0; i < size; i++) {
            positions.push(rows[row + i] + (col + 1));
        }
    }

    return positions;
}

// Random place ships
function randomPlaceShips() {
    // Clear existing
    placedShips = [];
    document.querySelectorAll('.ship-item').forEach(item => item.classList.remove('placed'));
    document.querySelectorAll('#placementCells .cell').forEach(cell => cell.classList.remove('ship'));

    shipsToPlace.forEach(ship => {
        let placed = false;
        let attempts = 0;

        while (!placed && attempts < 100) {
            const orientation = Math.random() < 0.5 ? 'H' : 'V';
            const row = Math.floor(Math.random() * 10);
            const col = Math.floor(Math.random() * 10);

            if (canPlaceShip(row, col, ship.size, orientation)) {
                placeShip(row, col, ship, orientation);
                placedShips.push({
                    type: ship.type,
                    size: ship.size,
                    row: row,
                    col: col,
                    orientation: orientation,
                    positions: getShipPositions(row, col, ship.size, orientation)
                });
                document.querySelector(`[data-ship="${ship.type}"]`).classList.add('placed');
                placed = true;
            }
            attempts++;
        }
    });

    document.getElementById('startGame').disabled = false;
    showMessage('Ships randomly placed! Click "Start Game" to begin.');
    saveState();
}

// Show handoff screen between players
function showHandoff(title, message, callback) {
    document.getElementById('handoffTitle').textContent = title;
    document.getElementById('handoffMessage').textContent = message;
    const modal = document.getElementById('handoffModal');
    modal.classList.add('show');

    const readyBtn = document.getElementById('handoffReady');
    const handler = () => {
        modal.classList.remove('show');
        readyBtn.removeEventListener('click', handler);
        callback();
    };
    readyBtn.addEventListener('click', handler);
}

// Handle the start/confirm button click
function handleStartGameClick() {
    if (gameMode === 'ai') {
        startGamePhase();
        return;
    }

    // PvP mode
    if (pvpPlacementPhase === 1) {
        // P1 done placing — save and handoff to P2
        p1PlacedShips = [...placedShips];
        saveState();
        showHandoff('Pass to Player 2', 'Player 2, click Ready when the other player looks away.', () => {
            pvpPlacementPhase = 2;
            initPlacementPhase();
        });
    } else {
        // P2 done placing — save and start game
        p2PlacedShips = [...placedShips];
        startGamePhase();
    }
}

// Rebuild boards for the current player's perspective (PvP)
function rebuildBoards() {
    const playerContainer = document.getElementById('playerGrid');
    const computerContainer = document.getElementById('computerGrid');

    playerContainer.innerHTML = '';
    computerContainer.innerHTML = '';

    const playerGrid = createGridElement('player');
    const computerGrid = createGridElement('computer');

    playerContainer.appendChild(playerGrid);
    computerContainer.appendChild(computerGrid);

    const myShips = currentPlayer === 1 ? p1PlacedShips : p2PlacedShips;
    const opponentHits = currentPlayer === 1 ? p2AttackHits : p1AttackHits;
    const opponentMisses = currentPlayer === 1 ? p2AttackMisses : p1AttackMisses;
    const myHits = currentPlayer === 1 ? p1AttackHits : p2AttackHits;
    const myMisses = currentPlayer === 1 ? p1AttackMisses : p2AttackMisses;

    // Draw my ships on "Your Board"
    myShips.forEach(ship => {
        ship.positions.forEach(pos => {
            const [row, col] = coordToRowCol(pos);
            const cell = getCellElement('playerCells', row, col);
            cell.classList.add('ship');
        });
    });

    // Mark opponent's hits/misses on "Your Board"
    opponentHits.forEach(coord => {
        const [row, col] = coordToRowCol(coord);
        const cell = getCellElement('playerCells', row, col);
        cell.classList.add('hit');
    });
    opponentMisses.forEach(coord => {
        const [row, col] = coordToRowCol(coord);
        const cell = getCellElement('playerCells', row, col);
        cell.classList.add('miss');
    });

    // Mark my hits/misses on "Enemy Board"
    myHits.forEach(coord => {
        const [row, col] = coordToRowCol(coord);
        const cell = getCellElement('computerCells', row, col);
        cell.classList.add('hit');
    });
    myMisses.forEach(coord => {
        const [row, col] = coordToRowCol(coord);
        const cell = getCellElement('computerCells', row, col);
        cell.classList.add('miss');
    });

    // Update labels
    document.getElementById('playerBoardLabel').textContent = 'Player ' + currentPlayer + "'s Board";
    document.getElementById('enemyBoardLabel').textContent = 'Enemy Board';

    // Update score display for current player
    if (currentPlayer === 1) {
        shots = p1Shots; hits = p1Hits; misses = p1Misses;
    } else {
        shots = p2Shots; hits = p2Hits; misses = p2Misses;
    }
    updateScore();
}

// Start game phase
async function startGamePhase() {
    gameState = 'playing';
    gameActive = true;
    shots = 0;
    hits = 0;
    misses = 0;
    currentPlayer = 1;

    // Reset PvP attack tracking
    p1AttackHits = []; p1AttackMisses = [];
    p2AttackHits = []; p2AttackMisses = [];
    p1Shots = 0; p1Hits = 0; p1Misses = 0;
    p2Shots = 0; p2Hits = 0; p2Misses = 0;

    // Hide placement screen, show game screen
    document.getElementById('placementScreen').style.display = 'none';
    document.getElementById('gameScreen').style.display = 'block';

    // Build init request
    const initBody = {
        action: 'init',
        gameMode: gameMode,
        playerShips: gameMode === 'pvp' ? p1PlacedShips : placedShips
    };
    if (gameMode === 'pvp') {
        initBody.p2Ships = p2PlacedShips;
    }

    // Initialize game on server
    try {
        const response = await fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(initBody)
        });

        const data = await response.json();
        if (data.success) {
            if (gameMode === 'pvp') {
                // Build initial boards then show handoff for P1
                rebuildBoards();
                // Hide game screen temporarily for handoff
                document.getElementById('gameScreen').style.display = 'none';
                showHandoff('Player 1 Goes First', 'Player 1, click Ready to start your turn.', () => {
                    document.getElementById('gameScreen').style.display = 'block';
                    rebuildBoards();
                    saveState();
                    showMessage("Player 1's turn - click on enemy board to fire.");
                });
            } else {
                // AI mode — build boards normally
                const playerContainer = document.getElementById('playerGrid');
                const computerContainer = document.getElementById('computerGrid');

                playerContainer.innerHTML = '';
                computerContainer.innerHTML = '';

                const playerGrid = createGridElement('player');
                const computerGrid = createGridElement('computer');

                playerContainer.appendChild(playerGrid);
                computerContainer.appendChild(computerGrid);

                placedShips.forEach(ship => {
                    ship.positions.forEach(pos => {
                        const [row, col] = coordToRowCol(pos);
                        const cell = getCellElement('playerCells', row, col);
                        cell.classList.add('ship');
                    });
                });

                document.getElementById('playerBoardLabel').textContent = 'Your Board';
                document.getElementById('enemyBoardLabel').textContent = 'Enemy Board';
                updateScore();
                saveState();
                showMessage('Game started! Your turn - click on enemy board to fire.');
            }
        } else {
            showMessage('Error starting game: ' + data.message);
        }
    } catch (error) {
        console.error('Error starting game:', error);
        showMessage('Error starting game. Please try again.');
    }
}

// Handle player shot
async function handlePlayerShot(row, col, cell) {
    if (!gameActive || gameState !== 'playing') {
        showMessage('Game not active!');
        return;
    }

    if (cell.classList.contains('hit') || cell.classList.contains('miss')) {
        showMessage('Already fired here!');
        return;
    }

    const rows = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
    const coord = rows[row] + (col + 1);
    const action = (gameMode === 'pvp' && currentPlayer === 2) ? 'fire_p2' : 'fire';

    try {
        const response = await fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: action,
                coord: coord
            })
        });

        const data = await response.json();

        if (data.success) {
            const isHit = data.result === 'hit' || data.result === 'sunk';

            // Update tracking arrays
            if (gameMode === 'pvp') {
                if (currentPlayer === 1) {
                    p1Shots++;
                    if (isHit) { p1Hits++; p1AttackHits.push(coord); }
                    else { p1Misses++; p1AttackMisses.push(coord); }
                    shots = p1Shots; hits = p1Hits; misses = p1Misses;
                } else {
                    p2Shots++;
                    if (isHit) { p2Hits++; p2AttackHits.push(coord); }
                    else { p2Misses++; p2AttackMisses.push(coord); }
                    shots = p2Shots; hits = p2Hits; misses = p2Misses;
                }
            } else {
                shots++;
                if (isHit) { hits++; p1AttackHits.push(coord); }
                else { misses++; p1AttackMisses.push(coord); }
            }

            if (isHit) {
                cell.classList.add('hit');
                const msg = data.result === 'sunk' ? '💀 SUNK at ' + coord + '!' : '💥 HIT at ' + coord + '!';
                showMessage(msg);
            } else {
                cell.classList.add('miss');
                showMessage('💧 MISS at ' + coord);
            }

            updateScore();
            saveState();

            if (data.gameOver) {
                gameActive = false;
                if (gameMode === 'pvp') {
                    endGame(currentPlayer === 1);
                } else {
                    endGame(true);
                }
                return;
            }

            if (gameMode === 'pvp') {
                // PvP: handoff to other player
                const nextPlayer = currentPlayer === 1 ? 2 : 1;
                gameActive = false;
                setTimeout(() => {
                    // Hide game screen for handoff
                    document.getElementById('gameScreen').style.display = 'none';
                    showHandoff(
                        'Pass to Player ' + nextPlayer,
                        'Player ' + nextPlayer + ', click Ready when the other player looks away.',
                        () => {
                            currentPlayer = nextPlayer;
                            document.getElementById('gameScreen').style.display = 'block';
                            rebuildBoards();
                            gameActive = true;
                            saveState();
                            showMessage("Player " + currentPlayer + "'s turn - click on enemy board to fire.");
                        }
                    );
                }, 1500);
            } else {
                // AI mode: trigger AI turn
                setTimeout(() => handleAITurn(), 1000);
            }
        } else {
            showMessage('Error: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error firing shot:', error);
        showMessage('Error processing shot. Please try again.');
    }
}

// Handle AI turn
async function handleAITurn() {
    if (!gameActive) return;

    showMessage('🤖 Enemy is firing...');

    try {
        const response = await fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'ai_fire' })
        });

        const data = await response.json();

        if (data.success) {
            const [row, col] = coordToRowCol(data.coord);
            const cell = getCellElement('playerCells', row, col);

            setTimeout(() => {
                if (data.result === 'sunk') {
                    cell.classList.add('hit');
                    showMessage('💀 Enemy SUNK your ship at ' + data.coord + '!');
                } else if (data.result === 'hit') {
                    cell.classList.add('hit');
                    showMessage('💥 Enemy HIT your ship at ' + data.coord + '!');
                } else {
                    cell.classList.add('miss');
                    showMessage('💧 Enemy missed at ' + data.coord);
                }

                if (data.result === 'sunk' || data.result === 'hit') {
                    p2AttackHits.push(data.coord);
                } else {
                    p2AttackMisses.push(data.coord);
                }
                saveState();

                // Check if AI won
                if (data.gameOver) {
                    gameActive = false;
                    setTimeout(() => endGame(false), 1000); // AI wins
                } else {
                    setTimeout(() => showMessage('Your turn!'), 1500);
                }
            }, 500);
        }
    } catch (error) {
        console.error('Error with AI turn:', error);
    }
}

// Update score
function updateScore() {
    document.getElementById('shots').textContent = shots;
    document.getElementById('hits').textContent = hits;
    document.getElementById('misses').textContent = misses;
}

// Show message
function showMessage(text, className = '') {
    const messageEl = document.getElementById('message');
    messageEl.textContent = text;
    messageEl.className = 'message ' + className;
}

// End game and fetch lifetime stats
async function endGame(playerWon) {
    try {
        const response = await fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'end_game' })
        });

        const data = await response.json();
        showGameOverModal(playerWon, data.success ? data.stats : null);
    } catch (error) {
        console.error('Error ending game:', error);
        showGameOverModal(playerWon, null);
    }
}

// Show game over modal
function showGameOverModal(playerWon, lifetimeStats) {
    clearSavedState();
    const modal = document.getElementById('gameOverModal');

    // Update modal content
    const title = modal.querySelector('h2');
    const message = modal.querySelector('#winMessage');

    if (gameMode === 'pvp') {
        const winner = playerWon ? 'Player 1' : 'Player 2';
        title.textContent = winner + ' Wins!';
        message.textContent = winner + ' destroyed all enemy ships!';

        // Show the winning player's stats
        const winnerShots = playerWon ? p1Shots : p2Shots;
        const winnerHits = playerWon ? p1Hits : p2Hits;
        const winnerMisses = playerWon ? p1Misses : p2Misses;
        const accuracy = winnerShots > 0 ? ((winnerHits / winnerShots) * 100).toFixed(1) : 0;

        document.getElementById('finalShots').textContent = winnerShots;
        document.getElementById('finalHits').textContent = winnerHits;
        document.getElementById('finalMisses').textContent = winnerMisses;
        document.getElementById('finalAccuracy').textContent = accuracy + '%';
    } else {
        const accuracy = shots > 0 ? ((hits / shots) * 100).toFixed(1) : 0;

        if (playerWon) {
            title.textContent = '🎉 Victory! 🎉';
            message.textContent = 'You destroyed all enemy ships!';
        } else {
            title.textContent = '💀 Defeat 💀';
            message.textContent = 'The enemy destroyed all your ships!';
        }

        document.getElementById('finalShots').textContent = shots;
        document.getElementById('finalHits').textContent = hits;
        document.getElementById('finalMisses').textContent = misses;
        document.getElementById('finalAccuracy').textContent = accuracy + '%';
    }

    // Lifetime stats
    const lifetimeSection = document.getElementById('lifetimeStats');
    if (lifetimeStats) {
        document.getElementById('lifetimeTotalGames').textContent = lifetimeStats.totalGames;
        document.getElementById('lifetimeWins').textContent = lifetimeStats.wins;
        document.getElementById('lifetimeLosses').textContent = lifetimeStats.losses;
        document.getElementById('lifetimeBestAccuracy').textContent = lifetimeStats.bestAccuracy + '%';
        document.getElementById('lifetimeWinStreak').textContent = lifetimeStats.currentWinStreak;
        document.getElementById('lifetimeBestStreak').textContent = lifetimeStats.bestWinStreak;
        lifetimeSection.style.display = 'block';
    } else {
        lifetimeSection.style.display = 'none';
    }

    modal.classList.add('show');
}

// Hide game over modal
function hideGameOverModal() {
    document.getElementById('gameOverModal').classList.remove('show');
}

// Get cell element
function getCellElement(gridId, row, col) {
    const grid = document.getElementById(gridId);
    // Calculate index: each row has 11 elements (1 label + 10 cells)
    const index = row * 11 + col + 1;
    return grid.children[index];
}

// Convert coord to row/col
function coordToRowCol(coord) {
    const rows = { 'A': 0, 'B': 1, 'C': 2, 'D': 3, 'E': 4, 'F': 5, 'G': 6, 'H': 7, 'I': 8, 'J': 9 };
    const row = rows[coord[0]];
    const col = parseInt(coord.substring(1)) - 1;
    return [row, col];
}

// Reset game
function resetGame() {
    clearSavedState();
    hideGameOverModal();
    gameActive = false;
    gameState = 'mode_select';
    currentPlayer = 1;
    pvpPlacementPhase = 1;
    p1PlacedShips = [];
    p2PlacedShips = [];
    p1AttackHits = []; p1AttackMisses = [];
    p2AttackHits = []; p2AttackMisses = [];
    p1Shots = 0; p1Hits = 0; p1Misses = 0;
    p2Shots = 0; p2Hits = 0; p2Misses = 0;
    shots = 0; hits = 0; misses = 0;
    showModeSelection();
}

// Save game state to localStorage
function saveState() {
    const state = {
        gameState, gameMode, gameActive,
        shots, hits, misses,
        currentPlayer, pvpPlacementPhase,
        placedShips, p1PlacedShips, p2PlacedShips,
        p1AttackHits, p1AttackMisses,
        p2AttackHits, p2AttackMisses,
        p1Shots, p1Hits, p1Misses,
        p2Shots, p2Hits, p2Misses
    };
    localStorage.setItem('battleshipState', JSON.stringify(state));
}

// Clear saved state
function clearSavedState() {
    localStorage.removeItem('battleshipState');
}

// Restore game state from localStorage
function restoreState() {
    const saved = localStorage.getItem('battleshipState');
    if (!saved) return false;

    let state;
    try { state = JSON.parse(saved); } catch (e) { return false; }

    if (state.gameState !== 'placement' && state.gameState !== 'playing') return false;

    gameState = state.gameState;
    gameMode = state.gameMode;
    gameActive = state.gameActive;
    shots = state.shots; hits = state.hits; misses = state.misses;
    currentPlayer = state.currentPlayer;
    pvpPlacementPhase = state.pvpPlacementPhase;
    placedShips = state.placedShips || [];
    p1PlacedShips = state.p1PlacedShips || [];
    p2PlacedShips = state.p2PlacedShips || [];
    p1AttackHits = state.p1AttackHits || [];
    p1AttackMisses = state.p1AttackMisses || [];
    p2AttackHits = state.p2AttackHits || [];
    p2AttackMisses = state.p2AttackMisses || [];
    p1Shots = state.p1Shots; p1Hits = state.p1Hits; p1Misses = state.p1Misses;
    p2Shots = state.p2Shots; p2Hits = state.p2Hits; p2Misses = state.p2Misses;

    if (gameState === 'placement') {
        restorePlacementUI();
    } else {
        restorePlayingUI();
    }
    return true;
}

// Restore placement screen from saved state
function restorePlacementUI() {
    const savedShips = [...placedShips];
    initPlacementPhase(); // resets placedShips and builds fresh grid

    savedShips.forEach(ship => {
        placeShip(ship.row, ship.col, ship, ship.orientation);
        document.querySelector(`[data-ship="${ship.type}"]`).classList.add('placed');
        placedShips.push(ship);
    });

    if (placedShips.length === shipsToPlace.length) {
        document.getElementById('startGame').disabled = false;
        const btnLabel = document.getElementById('startGame').textContent;
        showMessage('All ships placed! Click "' + btnLabel + '" to continue.');
    }
}

// Restore game screen from saved state
function restorePlayingUI() {
    document.getElementById('modeScreen').style.display = 'none';
    document.getElementById('placementScreen').style.display = 'none';
    document.getElementById('gameScreen').style.display = 'block';

    if (gameMode === 'pvp') {
        rebuildBoards();
        showMessage("Player " + currentPlayer + "'s turn - click on enemy board to fire.");
    } else {
        const playerContainer = document.getElementById('playerGrid');
        const computerContainer = document.getElementById('computerGrid');
        playerContainer.innerHTML = '';
        computerContainer.innerHTML = '';
        playerContainer.appendChild(createGridElement('player'));
        computerContainer.appendChild(createGridElement('computer'));

        // Restore player ships
        placedShips.forEach(ship => {
            ship.positions.forEach(pos => {
                const [row, col] = coordToRowCol(pos);
                getCellElement('playerCells', row, col).classList.add('ship');
            });
        });

        // Restore player's shots on enemy board
        p1AttackHits.forEach(coord => {
            const [row, col] = coordToRowCol(coord);
            getCellElement('computerCells', row, col).classList.add('hit');
        });
        p1AttackMisses.forEach(coord => {
            const [row, col] = coordToRowCol(coord);
            getCellElement('computerCells', row, col).classList.add('miss');
        });

        // Restore AI's shots on player board
        p2AttackHits.forEach(coord => {
            const [row, col] = coordToRowCol(coord);
            getCellElement('playerCells', row, col).classList.add('hit');
        });
        p2AttackMisses.forEach(coord => {
            const [row, col] = coordToRowCol(coord);
            getCellElement('playerCells', row, col).classList.add('miss');
        });

        document.getElementById('playerBoardLabel').textContent = 'Your Board';
        document.getElementById('enemyBoardLabel').textContent = 'Enemy Board';
        updateScore();
        showMessage('Game restored! Your turn - click on enemy board to fire.');
    }
}
