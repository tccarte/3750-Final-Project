// Game state
let gameState = 'mode_select';
let gameActive = false;
let gameMode = 'ai';
let displayName = '';
let shots = 0;
let hits = 0;
let misses = 0;

// PvP state
let currentPlayer = 1;
let pvpPlacementPhase = 1;
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
let shipOrientation = 'H';
let playerShips = [];
let placedShips = [];

// Ships to place (5 ships)
const shipsToPlace = [
    { type: 'carrier', size: 5 },
    { type: 'battleship', size: 4 },
    { type: 'cruiser', size: 3 },
    { type: 'submarine', size: 3 },
    { type: 'destroyer', size: 2 }
];

// Ship display info
const shipInfo = {
    carrier:    { emoji: '🚢', name: 'Carrier' },
    battleship: { emoji: '⛴️', name: 'Battleship' },
    cruiser:    { emoji: '🛳️', name: 'Cruiser' },
    submarine:  { emoji: '🛟', name: 'Submarine' },
    destroyer:  { emoji: '⚓', name: 'Destroyer' }
};

// ===================== INIT =====================

document.addEventListener('DOMContentLoaded', () => {
    if (!restoreState()) {
        showModeSelection();
    }

    document.getElementById('modeAI').addEventListener('click', () => {
        displayName = document.getElementById('playerName').value.trim() || 'Guest';
        gameMode = 'ai';
        document.getElementById('modeScreen').style.display = 'none';
        initPlacementPhase();
    });

    document.getElementById('modePVP').addEventListener('click', () => {
        displayName = document.getElementById('playerName').value.trim() || 'Guest';
        gameMode = 'pvp';
        pvpPlacementPhase = 1;
        document.getElementById('modeScreen').style.display = 'none';
        initPlacementPhase();
    });

    document.getElementById('newGame').addEventListener('click', () => resetGame());
    document.getElementById('randomPlace').addEventListener('click', () => randomPlaceShips());
    document.getElementById('startGame').addEventListener('click', () => handleStartGameClick());
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
            console.log('=== DEBUG ===', data);
            alert('Debug info logged to console (F12)');
        } catch (error) {
            console.error('Debug error:', error);
        }
    });

    document.addEventListener('keydown', (e) => {
        if (gameState === 'placement' && (e.key === 'r' || e.key === 'R')) {
            shipOrientation = shipOrientation === 'H' ? 'V' : 'H';
            showMessage(`Orientation: ${shipOrientation === 'H' ? 'Horizontal' : 'Vertical'}`);
        }
    });

    // Leaderboard
    fetchLeaderboard();

    document.getElementById('resetStatsBtn').addEventListener('click', () => {
        document.getElementById('resetConfirmModal').classList.add('show');
    });
    document.getElementById('resetCancel').addEventListener('click', () => {
        document.getElementById('resetConfirmModal').classList.remove('show');
    });
    document.getElementById('resetConfirm').addEventListener('click', async () => {
        document.getElementById('resetConfirmModal').classList.remove('show');
        try {
            const resp = await fetch('game.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'reset_stats' })
            });
            const data = await resp.json();
            if (data.success) updateLeaderboard(data.stats);
        } catch (e) {
            console.error('Reset error:', e);
        }
    });
});

// ===================== LEADERBOARD =====================

async function fetchLeaderboard() {
    try {
        const resp = await fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_stats' })
        });
        const data = await resp.json();
        if (data.success) updateLeaderboard(data.stats);
    } catch (e) {
        console.error('Leaderboard fetch error:', e);
    }
}

function updateLeaderboard(stats) {
    document.getElementById('lbPlayerWins').textContent = stats.wins || 0;
    document.getElementById('lbAIWins').textContent = stats.losses || 0;
}

// ===================== SHIP TRACKER =====================

function initShipTrackers() {
    const allShips = shipsToPlace.map(s => ({
        type: s.type,
        size: s.size,
        sunk: false
    }));
    renderTrackerList('playerShipList', allShips);
    renderTrackerList('enemyShipList', allShips);
    updateTrackerCount('playerShipsLeft', allShips);
    updateTrackerCount('enemyShipsLeft', allShips);
}

function renderTrackerList(containerId, ships) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = '';
    ships.forEach(ship => {
        const info = shipInfo[ship.type] || { emoji: '🚢', name: ship.type };
        const el = document.createElement('div');
        el.className = 'tracker-ship' + (ship.sunk ? ' sunk' : '');
        el.dataset.type = ship.type;

        let pips = '';
        for (let i = 0; i < ship.size; i++) {
            pips += '<span class="tracker-pip"></span>';
        }

        el.innerHTML = `
            <span class="tracker-ship-icon">${info.emoji}</span>
            <span class="tracker-ship-name">${info.name}</span>
            <span class="tracker-ship-pips">${pips}</span>
        `;
        container.appendChild(el);
    });
}

function updateTrackerCount(elementId, ships) {
    const remaining = ships.filter(s => !s.sunk).length;
    const el = document.getElementById(elementId);
    if (el) el.textContent = remaining + ' remaining';
}

function updateShipTrackers(shipStatus) {
    if (!shipStatus) return;
    if (shipStatus.player) {
        renderTrackerList('playerShipList', shipStatus.player);
        updateTrackerCount('playerShipsLeft', shipStatus.player);
    }
    if (shipStatus.enemy) {
        renderTrackerList('enemyShipList', shipStatus.enemy);
        updateTrackerCount('enemyShipsLeft', shipStatus.enemy);
    }
}

// ===================== SCREENS =====================

function showModeSelection() {
    gameState = 'mode_select';
    document.getElementById('modeScreen').style.display = 'block';
    document.getElementById('placementScreen').style.display = 'none';
    document.getElementById('gameScreen').style.display = 'none';
    showMessage('Choose your game mode');
}

function initPlacementPhase() {
    gameState = 'placement';
    placedShips = [];
    document.getElementById('modeScreen').style.display = 'none';
    document.getElementById('placementScreen').style.display = 'block';
    document.getElementById('gameScreen').style.display = 'none';
    document.getElementById('startGame').disabled = true;

    if (gameMode === 'pvp') {
        const label = pvpPlacementPhase === 1 ? 'Player 1' : 'Player 2';
        document.getElementById('placementTitle').textContent = label + ' - Deploy Your Fleet';
        document.getElementById('startGame').textContent = pvpPlacementPhase === 1 ? 'Confirm Ships' : 'Start Game';
    } else {
        document.getElementById('placementTitle').textContent = 'Deploy Your Fleet';
        document.getElementById('startGame').textContent = 'Start Game →';
    }

    createPlacementGrid();
    setupShipSelection();
    showMessage('Select a ship and place it on your board');
}

// ===================== GRID CREATION =====================

function createPlacementGrid() {
    const container = document.getElementById('placementGrid');
    container.innerHTML = '';
    container.appendChild(createGridElement('placement'));
}

function createGridElement(gridId) {
    const wrapper = document.createElement('div');
    const rows = ['A','B','C','D','E','F','G','H','I','J'];

    const header = document.createElement('div');
    header.className = 'grid-header';
    header.innerHTML = '<div class="corner"></div>';
    for (let i = 1; i <= 10; i++) {
        header.innerHTML += `<div class="col-label">${i}</div>`;
    }
    wrapper.appendChild(header);

    const grid = document.createElement('div');
    grid.className = 'grid';
    grid.id = gridId + 'Cells';

    for (let i = 0; i < 10; i++) {
        const rowLabel = document.createElement('div');
        rowLabel.className = 'row-label';
        rowLabel.textContent = rows[i];
        grid.appendChild(rowLabel);

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

// ===================== SHIP SELECTION =====================

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
                showMessage(`Selected ${selectedShip.type} (size ${selectedShip.size}). Click to place, R to rotate.`);
            }
        });
    });
}

// ===================== PLACEMENT LOGIC =====================

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

function clearPlacementPreview() {
    document.querySelectorAll('.valid-placement, .invalid-placement').forEach(cell => {
        cell.classList.remove('valid-placement', 'invalid-placement');
    });
}

function handlePlacementClick(row, col) {
    if (!selectedShip) {
        showMessage('Select a ship first!');
        return;
    }
    if (canPlaceShip(row, col, selectedShip.size, shipOrientation)) {
        placeShip(row, col, selectedShip, shipOrientation);
        document.querySelector(`[data-ship="${selectedShip.type}"]`).classList.add('placed');
        placedShips.push({
            type: selectedShip.type,
            size: selectedShip.size,
            row, col,
            orientation: shipOrientation,
            positions: getShipPositions(row, col, selectedShip.size, shipOrientation)
        });
        selectedShip = null;
        clearPlacementPreview();

        if (placedShips.length === shipsToPlace.length) {
            document.getElementById('startGame').disabled = false;
            showMessage('All ships placed! Click "Start Game" to begin.');
        } else {
            showMessage('Ship placed! Select next ship.');
        }
        saveState();
    } else {
        showMessage('Cannot place ship here!');
    }
}

function canPlaceShip(row, col, size, orientation) {
    if (orientation === 'H') {
        if (col + size > 10) return false;
        for (let i = 0; i < size; i++) {
            if (getCellElement('placementCells', row, col + i).classList.contains('ship')) return false;
        }
    } else {
        if (row + size > 10) return false;
        for (let i = 0; i < size; i++) {
            if (getCellElement('placementCells', row + i, col).classList.contains('ship')) return false;
        }
    }
    return true;
}

function placeShip(row, col, ship, orientation) {
    const size = ship.size;
    if (orientation === 'H') {
        for (let i = 0; i < size; i++) getCellElement('placementCells', row, col + i).classList.add('ship');
    } else {
        for (let i = 0; i < size; i++) getCellElement('placementCells', row + i, col).classList.add('ship');
    }
}

function getShipPositions(row, col, size, orientation) {
    const rows = ['A','B','C','D','E','F','G','H','I','J'];
    const positions = [];
    if (orientation === 'H') {
        for (let i = 0; i < size; i++) positions.push(rows[row] + (col + i + 1));
    } else {
        for (let i = 0; i < size; i++) positions.push(rows[row + i] + (col + 1));
    }
    return positions;
}

function randomPlaceShips() {
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
                    type: ship.type, size: ship.size,
                    row, col, orientation,
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

// ===================== HANDOFF (PvP) =====================

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

function handleStartGameClick() {
    if (gameMode === 'ai') {
        startGamePhase();
        return;
    }
    if (pvpPlacementPhase === 1) {
        p1PlacedShips = [...placedShips];
        saveState();
        showHandoff('Pass to Player 2', 'Player 2, click Ready when the other player looks away.', () => {
            pvpPlacementPhase = 2;
            initPlacementPhase();
        });
    } else {
        p2PlacedShips = [...placedShips];
        startGamePhase();
    }
}

// ===================== PvP BOARDS =====================

function rebuildBoards() {
    const playerContainer = document.getElementById('playerGrid');
    const computerContainer = document.getElementById('computerGrid');
    playerContainer.innerHTML = '';
    computerContainer.innerHTML = '';
    playerContainer.appendChild(createGridElement('player'));
    computerContainer.appendChild(createGridElement('computer'));

    const myShips = currentPlayer === 1 ? p1PlacedShips : p2PlacedShips;
    const opHits = currentPlayer === 1 ? p2AttackHits : p1AttackHits;
    const opMisses = currentPlayer === 1 ? p2AttackMisses : p1AttackMisses;
    const myHits = currentPlayer === 1 ? p1AttackHits : p2AttackHits;
    const myMisses = currentPlayer === 1 ? p1AttackMisses : p2AttackMisses;

    myShips.forEach(ship => {
        ship.positions.forEach(pos => {
            const [r, c] = coordToRowCol(pos);
            getCellElement('playerCells', r, c).classList.add('ship');
        });
    });
    opHits.forEach(coord => {
        const [r, c] = coordToRowCol(coord);
        getCellElement('playerCells', r, c).classList.add('hit');
    });
    opMisses.forEach(coord => {
        const [r, c] = coordToRowCol(coord);
        getCellElement('playerCells', r, c).classList.add('miss');
    });
    myHits.forEach(coord => {
        const [r, c] = coordToRowCol(coord);
        getCellElement('computerCells', r, c).classList.add('hit');
    });
    myMisses.forEach(coord => {
        const [r, c] = coordToRowCol(coord);
        getCellElement('computerCells', r, c).classList.add('miss');
    });

    document.getElementById('playerBoardLabel').textContent = 'Player ' + currentPlayer + "'s Board";
    document.getElementById('enemyBoardLabel').textContent = 'Enemy Board';

    if (currentPlayer === 1) {
        shots = p1Shots; hits = p1Hits; misses = p1Misses;
    } else {
        shots = p2Shots; hits = p2Hits; misses = p2Misses;
    }
    updateScore();
}

// ===================== START GAME =====================

async function startGamePhase() {
    gameState = 'playing';
    gameActive = true;
    shots = 0; hits = 0; misses = 0;
    currentPlayer = 1;
    p1AttackHits = []; p1AttackMisses = [];
    p2AttackHits = []; p2AttackMisses = [];
    p1Shots = 0; p1Hits = 0; p1Misses = 0;
    p2Shots = 0; p2Hits = 0; p2Misses = 0;

    document.getElementById('placementScreen').style.display = 'none';
    document.getElementById('gameScreen').style.display = 'block';

    const initBody = {
        action: 'init',
        gameMode: gameMode,
        displayName: displayName,
        playerShips: gameMode === 'pvp' ? p1PlacedShips : placedShips
    };
    if (gameMode === 'pvp') initBody.p2Ships = p2PlacedShips;

    try {
        const response = await fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(initBody)
        });
        const data = await response.json();

        if (data.success) {
            // Init ship trackers
            initShipTrackers();
            if (data.shipStatus) updateShipTrackers(data.shipStatus);

            if (gameMode === 'pvp') {
                rebuildBoards();
                document.getElementById('gameScreen').style.display = 'none';
                showHandoff('Player 1 Goes First', 'Player 1, click Ready to start your turn.', () => {
                    document.getElementById('gameScreen').style.display = 'block';
                    rebuildBoards();
                    saveState();
                    showMessage("Player 1's turn - click on enemy board to fire.");
                });
            } else {
                const playerContainer = document.getElementById('playerGrid');
                const computerContainer = document.getElementById('computerGrid');
                playerContainer.innerHTML = '';
                computerContainer.innerHTML = '';
                playerContainer.appendChild(createGridElement('player'));
                computerContainer.appendChild(createGridElement('computer'));

                placedShips.forEach(ship => {
                    ship.positions.forEach(pos => {
                        const [r, c] = coordToRowCol(pos);
                        getCellElement('playerCells', r, c).classList.add('ship');
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

// ===================== PLAYER SHOT =====================

async function handlePlayerShot(row, col, cell) {
    if (!gameActive || gameState !== 'playing') {
        showMessage('Game not active!');
        return;
    }
    if (cell.classList.contains('hit') || cell.classList.contains('miss')) {
        showMessage('Already fired here!');
        return;
    }

    const rows = ['A','B','C','D','E','F','G','H','I','J'];
    const coord = rows[row] + (col + 1);
    const action = (gameMode === 'pvp' && currentPlayer === 2) ? 'fire_p2' : 'fire';

    try {
        const response = await fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, coord })
        });
        const data = await response.json();

        if (data.success) {
            const isHit = data.result === 'hit' || data.result === 'sunk';

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
                const msg = data.result === 'sunk'
                    ? '💀 SUNK ' + (data.sunkShipType || '') + ' at ' + coord + '!'
                    : '💥 HIT at ' + coord + '!';
                showMessage(msg);
            } else {
                cell.classList.add('miss');
                showMessage('💧 MISS at ' + coord);
            }

            updateScore();
            if (data.shipStatus) updateShipTrackers(data.shipStatus);
            saveState();

            if (data.gameOver) {
                gameActive = false;
                endGame(gameMode === 'pvp' ? currentPlayer === 1 : true);
                return;
            }

            if (gameMode === 'pvp') {
                const nextPlayer = currentPlayer === 1 ? 2 : 1;
                gameActive = false;
                setTimeout(() => {
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

// ===================== AI TURN =====================

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
                    showMessage('💀 Enemy SUNK your ' + (data.sunkShipType || 'ship') + ' at ' + data.coord + '!');
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

                if (data.shipStatus) updateShipTrackers(data.shipStatus);
                saveState();

                if (data.gameOver) {
                    gameActive = false;
                    setTimeout(() => endGame(false), 1000);
                } else {
                    setTimeout(() => showMessage('Your turn!'), 1500);
                }
            }, 500);
        }
    } catch (error) {
        console.error('Error with AI turn:', error);
    }
}

// ===================== SCORE & MESSAGES =====================

function updateScore() {
    document.getElementById('shots').textContent = shots;
    document.getElementById('hits').textContent = hits;
    document.getElementById('misses').textContent = misses;
}

function showMessage(text, className = '') {
    const el = document.getElementById('message');
    el.textContent = text;
    el.className = 'message ' + className;
}

// ===================== END GAME =====================

async function endGame(playerWon) {
    try {
        const response = await fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'end_game', playerWon: playerWon })
        });
        const data = await response.json();
        showGameOverModal(playerWon, data.success ? data.stats : null);
    } catch (error) {
        console.error('Error ending game:', error);
        showGameOverModal(playerWon, null);
    }
}

function showGameOverModal(playerWon, lifetimeStats) {
    clearSavedState();
    const modal = document.getElementById('gameOverModal');
    const title = modal.querySelector('h2');
    const message = modal.querySelector('#winMessage');

    if (gameMode === 'pvp') {
        const winner = playerWon ? 'Player 1' : 'Player 2';
        title.textContent = winner + ' Wins!';
        message.textContent = winner + ' destroyed all enemy ships!';
        const ws = playerWon ? p1Shots : p2Shots;
        const wh = playerWon ? p1Hits : p2Hits;
        const wm = playerWon ? p1Misses : p2Misses;
        const acc = ws > 0 ? ((wh / ws) * 100).toFixed(1) : 0;
        document.getElementById('finalShots').textContent = ws;
        document.getElementById('finalHits').textContent = wh;
        document.getElementById('finalMisses').textContent = wm;
        document.getElementById('finalAccuracy').textContent = acc + '%';
    } else {
        const acc = shots > 0 ? ((hits / shots) * 100).toFixed(1) : 0;
        title.textContent = playerWon ? '🎉 Victory! 🎉' : '💀 Defeat 💀';
        message.textContent = playerWon ? 'You destroyed all enemy ships!' : 'The enemy destroyed all your ships!';
        document.getElementById('finalShots').textContent = shots;
        document.getElementById('finalHits').textContent = hits;
        document.getElementById('finalMisses').textContent = misses;
        document.getElementById('finalAccuracy').textContent = acc + '%';
    }

    const lifetimeSection = document.getElementById('lifetimeStats');
    if (lifetimeStats) {
        document.getElementById('lifetimeTotalGames').textContent = lifetimeStats.totalGames;
        document.getElementById('lifetimeWins').textContent = lifetimeStats.wins;
        document.getElementById('lifetimeLosses').textContent = lifetimeStats.losses;
        document.getElementById('lifetimeBestAccuracy').textContent = lifetimeStats.bestAccuracy + '%';
        document.getElementById('lifetimeWinStreak').textContent = lifetimeStats.currentWinStreak;
        document.getElementById('lifetimeBestStreak').textContent = lifetimeStats.bestWinStreak;
        lifetimeSection.style.display = 'block';
        updateLeaderboard(lifetimeStats);
    } else {
        lifetimeSection.style.display = 'none';
        fetchLeaderboard();
    }

    modal.classList.add('show');
}

function hideGameOverModal() {
    document.getElementById('gameOverModal').classList.remove('show');
}

// ===================== HELPERS =====================

function getCellElement(gridId, row, col) {
    const grid = document.getElementById(gridId);
    const index = row * 11 + col + 1;
    return grid.children[index];
}

function coordToRowCol(coord) {
    const rows = {'A':0,'B':1,'C':2,'D':3,'E':4,'F':5,'G':6,'H':7,'I':8,'J':9};
    return [rows[coord[0]], parseInt(coord.substring(1)) - 1];
}

// ===================== RESET =====================

function resetGame() {
    clearSavedState();
    hideGameOverModal();
    gameActive = false;
    gameState = 'mode_select';
    currentPlayer = 1;
    pvpPlacementPhase = 1;
    p1PlacedShips = []; p2PlacedShips = [];
    p1AttackHits = []; p1AttackMisses = [];
    p2AttackHits = []; p2AttackMisses = [];
    p1Shots = 0; p1Hits = 0; p1Misses = 0;
    p2Shots = 0; p2Hits = 0; p2Misses = 0;
    shots = 0; hits = 0; misses = 0;
    showModeSelection();
}

// ===================== STATE PERSISTENCE (localStorage) =====================

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

function clearSavedState() {
    localStorage.removeItem('battleshipState');
}

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

function restorePlacementUI() {
    const savedShips = [...placedShips];
    initPlacementPhase();
    savedShips.forEach(ship => {
        placeShip(ship.row, ship.col, ship, ship.orientation);
        document.querySelector(`[data-ship="${ship.type}"]`).classList.add('placed');
        placedShips.push(ship);
    });
    if (placedShips.length === shipsToPlace.length) {
        document.getElementById('startGame').disabled = false;
        showMessage('All ships placed! Click "Start Game" to continue.');
    }
}

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

        placedShips.forEach(ship => {
            ship.positions.forEach(pos => {
                const [r, c] = coordToRowCol(pos);
                getCellElement('playerCells', r, c).classList.add('ship');
            });
        });

        p1AttackHits.forEach(coord => {
            const [r, c] = coordToRowCol(coord);
            getCellElement('computerCells', r, c).classList.add('hit');
        });
        p1AttackMisses.forEach(coord => {
            const [r, c] = coordToRowCol(coord);
            getCellElement('computerCells', r, c).classList.add('miss');
        });
        p2AttackHits.forEach(coord => {
            const [r, c] = coordToRowCol(coord);
            getCellElement('playerCells', r, c).classList.add('hit');
        });
        p2AttackMisses.forEach(coord => {
            const [r, c] = coordToRowCol(coord);
            getCellElement('playerCells', r, c).classList.add('miss');
        });

        document.getElementById('playerBoardLabel').textContent = 'Your Board';
        document.getElementById('enemyBoardLabel').textContent = 'Enemy Board';
        updateScore();
        showMessage('Game restored! Your turn - click on enemy board to fire.');
    }

    // Restore ship trackers from server
    initShipTrackers();
    fetchShipStatus();
}

async function fetchShipStatus() {
    try {
        const resp = await fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'ship_status' })
        });
        const data = await resp.json();
        if (data.success && data.shipStatus) updateShipTrackers(data.shipStatus);
    } catch (e) {
        console.error('Error fetching ship status:', e);
    }
}