TNT Run Plugin v1.2.0
A multiplayer TNT Run minigame for PocketMine-MP servers with advanced features and smooth gameplay.
What It Does
Players start on a platform made of TNT, sand, and gravel blocks. As they move around, blocks disappear beneath their feet, creating holes. The goal is to keep moving and avoid falling through gaps while other players create more holes. Last player standing wins!
Key Features
Arena Management

Create multiple arenas using a simple selection tool
Players spawn randomly across the platform for fair gameplay
Arenas automatically reset after each game

Smart Game Flow

Players can't move during countdown (prevents cheating)
60-second wait time + 5-second countdown before start
Games become PvP combat after 3 minutes if still ongoing

PvP Combat System

Players get diamond armor and sword when PvP activates
Special items: Speed boost feather and launch-up emerald
Combat is disabled for the first 3 minutes

Player-Friendly Features

Click signs with empty hands to join games
Eliminated players become spectators and can leave anytime
Automatic transfer back to hub server when games end

Commands
For Players:

/jointnt <arena> - Join a specific arena
/listtnt - See all available arenas
/leave - Exit your current game

For Admins:

/createtnt <arena> - Create a new arena
/deltnt <arena> - Delete an arena
/tntedit on/off - Toggle setup mode

Setup

Use /createtnt <name> to start creating an arena
Use the golden axe to select two corners of your platform
Place signs with [Arena] on line 1 and arena name on line 2
Configure settings in config.yml as needed

Perfect for minigame servers looking for an engaging, automated TNT Run experience that handles everything from player management to game flow.
