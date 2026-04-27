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

// Online PvP state
let onlinePlayerId = null;
let onlineGameId = null;
let onlineTurnOrder = null; // 0 = creator, 1 = joiner
let onlineMyShips = [];     // [{row, col}] placed ships
let onlineMyFired = [];     // moves I fired
let onlineTheirFired = [];  // moves opponent fired
let onlineShipsPlaced = []; // cells clicked during online placement
let onlineMyTurn = false;
let onlinePollingInterval = null;
let lobbyPollingInterval = null;
let onlineGridSize = 10;
const API_BASE = '/api';

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
    const themeBtn = document.getElementById('themeToggle');
    const applyTheme = (light) => {
        document.body.classList.toggle('light', light);
        themeBtn.textContent = light ? '🌙' : '☀️';
    };
    applyTheme(localStorage.getItem('theme') === 'light');
    themeBtn.addEventListener('click', () => {
        const isLight = !document.body.classList.contains('light');
        localStorage.setItem('theme', isLight ? 'light' : 'dark');
        applyTheme(isLight);
    });

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

    document.getElementById('modeOnline').addEventListener('click', () => {
        displayName = document.getElementById('playerName').value.trim() || 'Guest';
        gameMode = 'online';
        document.getElementById('modeScreen').style.display = 'none';
        document.getElementById('onlineLobby').style.display = 'block';
        startLobbyPolling();
    });
    document.getElementById('onlineBack').addEventListener('click', () => {
        stopLobbyPolling();
        document.getElementById('onlineLobby').style.display = 'none';
        document.getElementById('modeScreen').style.display = 'block';
        document.getElementById('onlineMessage').textContent = '';
    });
    document.getElementById('onlineCreate').addEventListener('click', createOnlineGame);
    document.getElementById('onlineConfirmPlacement').addEventListener('click', confirmOnlinePlacement);

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
            body: JSON.stringify({ action: 'get_stats', displayName: displayName })
        });
        const data = await resp.json();
        if (data.success) updateLeaderboard(data.stats);
    } catch (e) {
        console.error('Leaderboard fetch error:', e);
    }
}

function updateLeaderboard(stats) {
    if (!stats) return;
    const pw = document.getElementById('lbPlayerWins');
    const aw = document.getElementById('lbAIWins');
    if (pw) pw.textContent = stats.wins || 0;
    if (aw) aw.textContent = stats.losses || 0;
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

function createPlacementGrid(size = 10) {
    const container = document.getElementById('placementGrid');
    container.innerHTML = '';
    container.appendChild(createGridElement('placement', size));
}

function createGridElement(gridId, size = 10) {
    const wrapper = document.createElement('div');
    const rowLabels = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('').slice(0, size);

    const header = document.createElement('div');
    header.className = 'grid-header';
    header.style.gridTemplateColumns = `30px repeat(${size}, 38px)`;
    header.innerHTML = '<div class="corner"></div>';
    for (let i = 1; i <= size; i++) {
        header.innerHTML += `<div class="col-label">${i}</div>`;
    }
    wrapper.appendChild(header);

    const grid = document.createElement('div');
    grid.className = 'grid';
    grid.id = gridId + 'Cells';
    grid.style.gridTemplateColumns = `30px repeat(${size}, 38px)`;

    for (let i = 0; i < size; i++) {
        const rowLabel = document.createElement('div');
        rowLabel.className = 'row-label';
        rowLabel.textContent = rowLabels[i];
        grid.appendChild(rowLabel);

        for (let j = 0; j < size; j++) {
            const cell = document.createElement('div');
            cell.className = 'cell';
            cell.dataset.row = i;
            cell.dataset.col = j;
            cell.dataset.coord = rowLabels[i] + (j + 1);

            if (gridId === 'placement') {
                cell.addEventListener('click', () => handlePlacementClick(i, j));
                cell.addEventListener('mouseenter', () => showPlacementPreview(i, j));
                cell.addEventListener('mouseleave', () => clearPlacementPreview());
            } else if (gridId === 'computer') {
                cell.addEventListener('click', () => {
                    if (gameMode === 'online') handleOnlineFire(i, j, cell);
                    else handlePlayerShot(i, j, cell);
                });
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
    if (gameMode === 'online') {
        handleOnlinePlacementClick(row, col);
        return;
    }
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
    const gridSize = gameMode === 'online' ? onlineGridSize : 10;
    if (orientation === 'H') {
        if (col + size > gridSize) return false;
        for (let i = 0; i < size; i++) {
            if (getCellElement('placementCells', row, col + i).classList.contains('ship')) return false;
        }
    } else {
        if (row + size > gridSize) return false;
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
    const rows = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
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

    const size = gameMode === 'online' ? onlineGridSize : 10;

    shipsToPlace.forEach(ship => {
        let placed = false;
        let attempts = 0;
        while (!placed && attempts < 200) {
            const orientation = Math.random() < 0.5 ? 'H' : 'V';
            const row = Math.floor(Math.random() * size);
            const col = Math.floor(Math.random() * size);
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

    if (gameMode === 'online') {
        document.getElementById('onlineConfirmPlacement').disabled = false;
        showMessage('Ships randomly placed! Click "Confirm Placement" to ready up.');
    } else {
        document.getElementById('startGame').disabled = false;
        showMessage('Ships randomly placed! Click "Start Game" to begin.');
    }
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
    if (gameMode === 'online') {
        stopOnlinePolling();
        const ti = document.getElementById('turnIndicator');
        if (ti) ti.style.display = 'none';
        showGameOverModal(playerWon, null);
        return;
    }
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
    if (!grid) return null;
    return grid.querySelector(`[data-row="${row}"][data-col="${col}"]`);
}

function coordToRowCol(coord) {
    const rows = {'A':0,'B':1,'C':2,'D':3,'E':4,'F':5,'G':6,'H':7,'I':8,'J':9};
    return [rows[coord[0]], parseInt(coord.substring(1)) - 1];
}

// ===================== RESET =====================

function resetGame() {
    stopOnlinePolling();
    stopLobbyPolling();
    const ti = document.getElementById('turnIndicator');
    if (ti) { ti.style.display = 'none'; ti.className = 'turn-indicator'; }
    const mhp = document.getElementById('moveHistoryPanel');
    if (mhp) {
        mhp.style.display = 'none';
        document.getElementById('moveHistoryList').innerHTML = '';
        document.getElementById('moveCount').textContent = '0 moves';
    }
    onlinePlayerId = null; onlineGameId = null; onlineTurnOrder = null;
    onlineMyShips = []; onlineMyFired = []; onlineTheirFired = [];
    onlineShipsPlaced = []; onlineMyTurn = false;
    document.getElementById('onlineLobby').style.display = 'none';
    document.getElementById('waitingScreen').style.display = 'none';
    document.querySelector('.ship-selection').style.display = '';
    document.getElementById('onlineShipSelection').style.display = 'none';
    document.getElementById('onlineConfirmPlacement').style.display = 'none';
    document.getElementById('startGame').style.display = '';
    document.getElementById('randomPlace').style.display = '';
    document.getElementById('placementSubtitle').style.display = '';

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

// ===================== ONLINE PvP =====================

async function apiPost(endpoint, body) {
    const resp = await fetch(API_BASE + endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
    return resp.json();
}

async function apiGet(endpoint) {
    const resp = await fetch(API_BASE + endpoint);
    return resp.json();
}

async function createOnlineGame() {
    const btn = document.getElementById('onlineCreate');
    btn.disabled = true;
    document.getElementById('onlineMessage').textContent = 'Creating game...';
    onlineGridSize = parseInt(document.getElementById('gridSizeSelect').value) || 10;
    stopLobbyPolling();
    try {
        const username = sanitizeUsername(displayName);
        const pResp = await apiPost('/players', { username });
        if (!pResp.player_id) throw new Error(pResp.error || 'Could not create player');
        onlinePlayerId = pResp.player_id;
        onlineTurnOrder = 0;

        const gResp = await apiPost('/games', { creator_id: onlinePlayerId, grid_size: onlineGridSize, max_players: 2 });
        if (!gResp.game_id) throw new Error(gResp.error || 'Could not create game');
        onlineGameId = gResp.game_id;

        document.getElementById('onlineLobby').style.display = 'none';
        showOnlinePlacement();
    } catch (e) {
        document.getElementById('onlineMessage').textContent = 'Error: ' + e.message;
        btn.disabled = false;
        startLobbyPolling();
    }
}

async function joinOnlineGame() {
    const gameIdVal = document.getElementById('onlineGameIdInput').value.trim();
    if (!gameIdVal) { document.getElementById('onlineMessage').textContent = 'Enter a Game ID'; return; }
    const btn = document.getElementById('onlineJoin');
    btn.disabled = true;
    document.getElementById('onlineMessage').textContent = 'Joining...';
    try {
        const pResp = await apiPost('/players', { username: displayName });
        if (!pResp.player_id) throw new Error(pResp.error || 'Could not create player');
        onlinePlayerId = pResp.player_id;
        onlineTurnOrder = 1;
        onlineGameId = parseInt(gameIdVal);

        const jResp = await apiPost('/games/' + onlineGameId + '/join', { player_id: onlinePlayerId });
        if (jResp.error) throw new Error(jResp.error);

        document.getElementById('onlineLobby').style.display = 'none';
        showOnlinePlacement();
    } catch (e) {
        document.getElementById('onlineMessage').textContent = 'Error: ' + e.message;
        btn.disabled = false;
    }
}

function showOnlinePlacement() {
    gameState = 'placement';
    placedShips = [];
    onlineShipsPlaced = [];
    selectedShip = null;
    shipOrientation = 'H';

    document.getElementById('placementScreen').style.display = 'block';
    document.querySelector('.ship-selection').style.display = 'block';
    document.getElementById('onlineShipSelection').style.display = 'none';
    document.getElementById('placementSubtitle').style.display = 'block';
    document.getElementById('startGame').style.display = 'none';
    document.getElementById('randomPlace').style.display = 'inline-block';
    document.getElementById('onlineConfirmPlacement').style.display = 'inline-block';
    document.getElementById('onlineConfirmPlacement').disabled = true;
    document.getElementById('placementTitle').textContent = 'Deploy Your Fleet';

    if (onlineTurnOrder === 0) {
        document.getElementById('onlineGameIdDisplay').textContent = 'Game ID: ' + onlineGameId + ' — share this with your opponent';
        document.getElementById('onlineGameIdDisplay').style.display = 'block';
    }

    createPlacementGrid(onlineGridSize);
    setupShipSelection();
    showMessage('Select a ship and place it on your board. Press R to rotate.');
}

function handleOnlinePlacementClick(row, col) {
    // Online placement now uses the same 5-ship multi-cell flow as local play.
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

        const btn = document.getElementById('onlineConfirmPlacement');
        if (placedShips.length === shipsToPlace.length) {
            btn.disabled = false;
            showMessage('All ships placed! Click "Confirm Placement" to ready up.');
        } else {
            btn.disabled = true;
            showMessage(`Ship placed! ${shipsToPlace.length - placedShips.length} ship(s) left.`);
        }
    } else {
        showMessage('Cannot place ship here!');
    }
}

async function confirmOnlinePlacement() {
    if (placedShips.length !== shipsToPlace.length) return;
    const btn = document.getElementById('onlineConfirmPlacement');
    btn.disabled = true;
    showMessage('Confirming placement...');

    // Flatten every ship cell into the coordinate list the API expects.
    // Each occupied cell becomes one entry, so a 5-cell carrier sends 5 entries.
    const flatCells = [];
    placedShips.forEach(ship => {
        for (let i = 0; i < ship.size; i++) {
            if (ship.orientation === 'H') {
                flatCells.push({ row: ship.row, col: ship.col + i });
            } else {
                flatCells.push({ row: ship.row + i, col: ship.col });
            }
        }
    });
    onlineShipsPlaced = flatCells;

    try {
        const resp = await apiPost('/games/' + onlineGameId + '/place', {
            player_id: onlinePlayerId,
            ships: flatCells
        });
        if (resp.error) throw new Error(resp.error);
        onlineMyShips = [...flatCells];
        document.getElementById('placementScreen').style.display = 'none';
        showWaitingScreen();
        startOnlinePolling();
    } catch (e) {
        showMessage('Error: ' + e.message);
        btn.disabled = false;
    }
}

function showWaitingScreen() {
    document.getElementById('waitingScreen').style.display = 'block';
    document.getElementById('waitingTitle').textContent = 'SHIPS PLACED';
    document.getElementById('waitingMessage').textContent = 'Waiting for opponent to place their ships...';
    if (onlineTurnOrder === 0) {
        document.getElementById('shareGameIdBox').style.display = 'block';
        document.getElementById('shareGameId').textContent = onlineGameId;
    }
}

function startOnlinePolling() {
    stopOnlinePolling();
    onlinePollingInterval = setInterval(checkOnlineGameState, 2000);
}

function stopOnlinePolling() {
    if (onlinePollingInterval) { clearInterval(onlinePollingInterval); onlinePollingInterval = null; }
}

async function checkOnlineGameState() {
    try {
        const game = await apiGet('/games/' + onlineGameId);
        if (game.error) return;

        const status = game.status;

        // Finished — stop everything and show result
        if (status === 'finished') {
            stopOnlinePolling();
            gameActive = false;
            // Make sure game screen is showing even if we missed the playing transition
            if (gameState !== 'playing') {
                document.getElementById('waitingScreen').style.display = 'none';
                await startOnlineGame(game);
            }
            await updateOnlineMoves();
            await endGame(game.winner_id === onlinePlayerId);
            return;
        }

        // Still in setup — keep waiting
        if (status === 'waiting_setup') {
            return;
        }

        // Transition from waiting_setup → playing
        if ((status === 'playing' || status === 'active') && gameState !== 'playing') {
            document.getElementById('waitingScreen').style.display = 'none';
            await startOnlineGame(game);
        }

        // Only sync turn/moves once game is actually playing
        if (gameState === 'playing') {
            onlineMyTurn = (game.current_turn_index === onlineTurnOrder);
            updateOnlineTurnUI();
            await updateOnlineMoves();
        }
    } catch (e) {
        console.error('Polling error:', e);
    }
}

async function startOnlineGame(_game) {
    gameState = 'playing';
    gameActive = true;
    shots = 0; hits = 0; misses = 0;
    onlineMyFired = []; onlineTheirFired = [];

    document.getElementById('gameScreen').style.display = 'block';
    document.getElementById('turnIndicator').style.display = 'block';
    document.getElementById('moveHistoryPanel').style.display = 'block';

    const playerContainer = document.getElementById('playerGrid');
    const computerContainer = document.getElementById('computerGrid');
    playerContainer.innerHTML = '';
    computerContainer.innerHTML = '';
    playerContainer.appendChild(createGridElement('player', onlineGridSize));
    computerContainer.appendChild(createGridElement('computer', onlineGridSize));

    onlineMyShips.forEach(s => {
        const cell = getCellElement('playerCells', s.row, s.col);
        if (cell) cell.classList.add('ship');
    });

    document.getElementById('playerBoardLabel').textContent = displayName + "'s Board";
    document.getElementById('enemyBoardLabel').textContent = 'Enemy Board';
    initShipTrackers();
    updateScore();
}

// Recomputes the fleet trackers for online PvP from move history.
//
// Your own fleet: we know the exact layout (placedShips), so a ship is sunk
// when every cell of that ship appears in the opponent's hit list.
//
// Enemy fleet: we don't know their layout, so we can't attribute specific
// hits to specific ships. Instead we count total hits landed and walk the
// known ship-size list [5,4,3,3,2] in order, marking ships sunk once the
// cumulative hit count reaches each size threshold. The total count is
// always right; the identity of which ship is marked sunk is a best guess
// (real sink order depends on where the opponent placed ships). If the
// backend later returns a sunk flag, switch this to use that instead.
function updateOnlineShipTrackers() {
    // --- your fleet ---
    const myHitSet = new Set(
        onlineTheirFired
            .filter(m => m.result === 'hit')
            .map(m => m.row + ',' + m.col)
    );
    const myStatus = placedShips.map(ship => {
        const allHit = ship.positions.every(coord => {
            const [r, c] = coordToRowCol(coord);
            return myHitSet.has(r + ',' + c);
        });
        return { type: ship.type, size: ship.size, sunk: allHit };
    });

    // --- enemy fleet (best-guess without server-side sunk flag) ---
    const myHitCount = onlineMyFired.filter(m => m.result === 'hit').length;
    const enemySizes = shipsToPlace.slice().sort((a, b) => b.size - a.size);
    let remaining = myHitCount;
    const enemyStatus = shipsToPlace.map(ship => ({
        type: ship.type,
        size: ship.size,
        sunk: false
    }));
    // Mark ships sunk greedily by largest size first until we run out of
    // hits. This at least keeps the "X remaining" count accurate.
    for (const s of enemySizes) {
        if (remaining >= s.size) {
            remaining -= s.size;
            const idx = enemyStatus.findIndex(e => !e.sunk && e.type === s.type && e.size === s.size);
            if (idx !== -1) enemyStatus[idx].sunk = true;
        } else {
            break;
        }
    }

    updateShipTrackers({ player: myStatus, enemy: enemyStatus });
}

async function updateOnlineMoves() {
    try {
        const data = await apiGet('/games/' + onlineGameId + '/moves');
        if (!data.moves) return;

        const myMoves = data.moves.filter(m => m.player_id === onlinePlayerId);
        const theirMoves = data.moves.filter(m => m.player_id !== onlinePlayerId);

        myMoves.slice(onlineMyFired.length).forEach(move => {
            onlineMyFired.push(move);
            const cell = getCellElement('computerCells', move.row, move.col);
            if (cell) cell.classList.add(move.result === 'hit' ? 'hit' : 'miss');
        });

        theirMoves.slice(onlineTheirFired.length).forEach(move => {
            onlineTheirFired.push(move);
            const cell = getCellElement('playerCells', move.row, move.col);
            if (cell) cell.classList.add(move.result === 'hit' ? 'hit' : 'miss');
        });

        renderMoveHistory(data.moves);
        updateOnlineShipTrackers();
    } catch (e) {
        console.error('Move update error:', e);
    }
}

function updateOnlineTurnUI() {
    if (!gameActive) return;
    const indicator = document.getElementById('turnIndicator');
    if (onlineMyTurn) {
        if (indicator) { indicator.textContent = '⚡ YOUR TURN — Click the enemy board to fire!'; indicator.className = 'turn-indicator turn-yours'; }
        showMessage("Your turn — click the enemy board to fire!");
    } else {
        if (indicator) { indicator.textContent = '⏳ Waiting for opponent...'; indicator.className = 'turn-indicator turn-theirs'; }
        showMessage("Waiting for opponent to fire...");
    }
}

// ===================== LOBBY =====================

function sanitizeUsername(name) {
    return (name || 'Player').replace(/[^a-zA-Z0-9_]/g, '_').replace(/^_+|_+$/g, '').slice(0, 30) || 'Player';
}

function startLobbyPolling() {
    stopLobbyPolling();
    fetchOpenGames();
    lobbyPollingInterval = setInterval(fetchOpenGames, 3000);
}

function stopLobbyPolling() {
    if (lobbyPollingInterval) { clearInterval(lobbyPollingInterval); lobbyPollingInterval = null; }
}

async function fetchOpenGames() {
    try {
        const data = await apiGet('/games');
        if (data.games !== undefined) renderGameList(data.games);
    } catch (e) {}
}

function renderGameList(games) {
    const list = document.getElementById('gamesList');
    if (!list) return;
    if (games.length === 0) {
        list.innerHTML = '<div class="games-empty">No open games — be the first to create one!</div>';
        return;
    }
    list.innerHTML = '';
    games.forEach(game => {
        const item = document.createElement('div');
        item.className = 'game-item';
        item.innerHTML = `
            <div class="game-item-info">
                <span class="game-item-id">#${game.game_id}</span>
                <span class="game-item-creator">${game.creator_name}</span>
                <span class="game-item-meta">${game.grid_size}×${game.grid_size} · ${game.player_count}/${game.max_players} players</span>
            </div>
            <button class="btn btn-primary game-join-btn">Join</button>
        `;
        item.querySelector('.game-join-btn').addEventListener('click', () => {
            joinGameFromLobby(game.game_id, game.grid_size);
        });
        list.appendChild(item);
    });
}

async function joinGameFromLobby(gameId, gridSize) {
    stopLobbyPolling();
    onlineGridSize = gridSize || 10;
    onlineTurnOrder = 1;
    onlineGameId = gameId;
    document.getElementById('onlineMessage').textContent = 'Joining...';
    try {
        const username = sanitizeUsername(displayName);
        const pResp = await apiPost('/players', { username });
        if (!pResp.player_id) throw new Error(pResp.error || 'Could not create player');
        onlinePlayerId = pResp.player_id;

        const jResp = await apiPost('/games/' + onlineGameId + '/join', { player_id: onlinePlayerId });
        if (jResp.error) throw new Error(jResp.error);

        document.getElementById('onlineLobby').style.display = 'none';
        showOnlinePlacement();
    } catch (e) {
        document.getElementById('onlineMessage').textContent = 'Error: ' + e.message;
        startLobbyPolling();
    }
}

// ===================== MOVE HISTORY =====================

function renderMoveHistory(moves) {
    const list = document.getElementById('moveHistoryList');
    const countEl = document.getElementById('moveCount');
    if (!list) return;

    countEl.textContent = moves.length + ' move' + (moves.length !== 1 ? 's' : '');
    const currentCount = list.querySelectorAll('.move-entry').length;
    const newMoves = moves.slice(currentCount);

    newMoves.forEach(move => {
        const isMe = move.player_id === onlinePlayerId;
        const rowLabel = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'[move.row] || move.row;
        const coord = rowLabel + (move.col + 1);
        let timeStr = '';
        if (move.created_at) {
            try { timeStr = new Date(move.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }); } catch (e) {}
        }
        const entry = document.createElement('div');
        entry.className = 'move-entry ' + (isMe ? 'move-you' : 'move-opponent');
        entry.innerHTML = `
            <span class="move-icon">${move.result === 'hit' ? '💥' : '💧'}</span>
            <span class="move-who">${isMe ? 'YOU' : 'OPP'}</span>
            <span class="move-coord">${coord}</span>
            <span class="move-result ${move.result}">${move.result.toUpperCase()}</span>
            <span class="move-time">${timeStr}</span>
        `;
        list.appendChild(entry);
    });

    list.scrollTop = list.scrollHeight;
}

async function handleOnlineFire(row, col, cell) {
    if (!onlineMyTurn) { showMessage("It's not your turn yet!"); return; }
    if (cell.classList.contains('hit') || cell.classList.contains('miss')) { showMessage('Already fired here!'); return; }

    onlineMyTurn = false;
    updateOnlineTurnUI();
    try {
        const resp = await apiPost('/games/' + onlineGameId + '/fire', {
            player_id: onlinePlayerId, row, col
        });
        if (resp.error) { showMessage('Error: ' + resp.error); onlineMyTurn = true; updateOnlineTurnUI(); return; }

        const isHit = resp.result === 'hit';
        cell.classList.add(isHit ? 'hit' : 'miss');
        onlineMyFired.push({ player_id: onlinePlayerId, row, col, result: resp.result });

        shots++; if (isHit) hits++; else misses++;
        updateScore();
        showMessage(isHit ? '💥 HIT! Waiting for opponent...' : '💧 Miss. Waiting for opponent...');

        if (resp.game_status === 'finished') {
            stopOnlinePolling();
            gameActive = false;
            await endGame(resp.winner_id === onlinePlayerId);
            return;
        }

        try { updateOnlineShipTrackers(); } catch (e) { console.error('Tracker error:', e); }

        // Immediately refresh state so the opponent's turn shows up right away
        // without waiting for the 2-second polling tick.
        checkOnlineGameState();
    } catch (e) {
        showMessage('Error: ' + e.message);
        onlineMyTurn = true;
        updateOnlineTurnUI();
    }
}
