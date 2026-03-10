
## High-Level Overview

```
┌─────────────────┐                    ┌─────────────────┐
│   BROWSER       │                    │   SERVER        │
│  (Frontend)     │   ◄── AJAX ──►     │   (Backend)     │
├─────────────────┤                    ├─────────────────┤
│ - Display UI    │                    │ - Game logic    │
│ - Ship placement│                    │ - Validation    │
│ - Player input  │                    │ - AI opponent   │
│ - Animations    │                    │ - State storage │
└─────────────────┘                    └─────────────────┘
```

## What the Client Does

**Files**: `index.php`, `script.js`, `styles.css`

- Shows two game boards (yours and enemy's)
- Handles ship placement with drag-and-drop feel
- Sends your shots to the server
- Updates the UI when you or the AI hits/misses
- Plays sounds and shows animations

## What the Server Does

**File**: `game.php`

- Stores all game state in PHP sessions
- Validates every move (prevents cheating)
- Places computer ships randomly at game start
- Runs AI logic to shoot back at you
- Never tells the client where computer ships are (until game over)

## Game State

Everything important lives **on the server** in `$_SESSION`:

```php
$_SESSION['player_ships']       // Your ships with hit counts
$_SESSION['computer_ships']     // AI ships (hidden from client)
$_SESSION['player_hits']        // Where you hit the AI
$_SESSION['computer_hits']      // Where AI hit you
$_SESSION['ai_target_queue']    // AI's next targets (smart mode)
```

The client only stores **temporary UI state** like what ship you're currently placing.

## How It Works

### 1. Ship Placement Phase
- You place 3 ships (sizes: 5, 3, 2) on your board
- Press R to rotate, click to place
- Client validates placement (no overlaps, in bounds)
- Click "Start Game" sends your ships to server

### 2. Gameplay Phase
**Player Turn:**
- Click enemy board → Send coordinate to server
- Server checks if it's a hit → Responds with result
- Client shows red (hit) or green (miss)

**AI Turn:**
- Client requests AI turn
- Server picks a cell using **hunt/target logic**:
  - **Hunt mode**: Random shots until it hits
  - **Target mode**: After a hit, tries adjacent cells (N/S/E/W)
- Server responds with coordinate and result
- Client updates your board

### 3. Game Over
- First to sink all enemy ships wins
- Modal shows stats (shots, accuracy, etc.)

## AI Logic
If target_queue is empty:
    → HUNT MODE: Pick random cell
Else:
    → TARGET MODE: Pop next cell from queue

If AI scores a hit:
    → Add all adjacent cells to queue
    → Keep firing at those until ship is sunk

If ship sinks:
    → Clear queue, back to hunt mode
```

## State Transitions
Page Load
    ↓
[Ship Placement]
    ↓ Click "Start Game"
Send ships to server → Server generates AI ships
    ↓
[Gameplay Loop]
    Player fires → AI fires → Repeat
    ↓ Until someone wins
[Game Over]
    ↓ Click "Play Again"
Back to Ship Placement

## File Structure

```
Battleship/
├── index.php       # Main HTML page
├── game.php        # Backend API
├── script.js       # Game logic + UI
├── styles.css      # Styling
└── sounds/         # Audio effects

