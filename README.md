TNT Run Plugin for PocketMine-MP
A comprehensive TNT Run minigame plugin featuring advanced arena management, PVP mechanics, and player distribution systems.
Core Gameplay
TNT Run Mechanics:

Players spawn randomly distributed across fall-blocks (TNT, sand, gravel) on the arena's top layer
Blocks disappear beneath players as they walk, creating holes in the platform
Players must keep moving to avoid falling through gaps created by themselves and others
Elimination occurs when players fall below a configurable Y-level threshold
Last player remaining wins the round

Arena System
Arena Creation & Management:

Visual region selection using golden axe tool with position confirmation
Automatic spawn point generation across fall-blocks in the selected region
Complete arena data persistence with block restoration after games
Support for multiple simultaneous arenas with independent game states

Player Flow:

Movement restriction during waiting/countdown phases prevents early positioning advantages
Two-phase game start: 60-second waiting period + 5-second final countdown
Random spawn distribution ensures fair starting positions across the arena
Configurable player limits and minimum requirements per arena

Advanced PVP System
Timed PVP Activation:

PVP damage prevention for first 3 minutes of gameplay
30-second warning before PVP mode activation
Automatic diamond gear distribution when PVP begins
Complete armor set (helmet, chestplate, leggings, boots) auto-equipped

Special Items:

Diamond Sword (slot 1): Primary combat weapon
Custom Emerald (383:37, slot 2): Launch boost ability with 3-minute cooldown
Feather (slot 3): Speed II buff for 7 seconds with 20-second cooldown

Player Management
Spectator System:

Eliminated players automatically become spectators with elevated viewing positions
Bed item provided for easy hub return
Spectators can observe ongoing matches without interference

Post-Game Handling:

Configurable staggered player transfers to prevent server lag
Players teleported to spawn before hub transfer or server kick
Customizable delay timing between individual player actions

Technical Features
Sign Integration:

Dynamic sign detection without config storage to prevent ghost blocks
Live status updates showing player counts and game states
Automatic sign formatting with arena information
Empty-hand interaction for joining games

Configuration System:

Comprehensive settings for all game mechanics
Configurable elimination rules, timing systems, and item behaviors
Hub server integration with transfer protocols
Flexible PVP and ability cooldown management

Supported Game Modes

Waiting Phase: Movement frozen, players accumulate
Active Phase: Standard TNT Run gameplay with fall-block mechanics
PVP Phase: Combat enabled with special items after time threshold
Spectator Phase: Eliminated players can observe and easily exit

The plugin provides a complete TNT Run experience with professional-grade arena management, balanced gameplay mechanics, and robust technical infrastructure suitable for multiplayer servers.
