<?php
namespace TNTRun;

use pocketmine\Player;
use pocketmine\level\Position;
use pocketmine\block\Block;
use pocketmine\math\Vector3;
use pocketmine\item\Item;
use TNTRun\Task\ArenaTickTask;
use TNTRun\Task\WaitingCountdownTask;
use TNTRun\Task\PVPTimerTask;

class Arena {

    private $plugin;
    private $name;
    private $world;
    private $spawn;
    private $region;
    private $spawnPositions = []; // Array of spawn positions on fall-blocks
    private $alivePlayers = [];
    private $spectatorPlayers = [];
    private $active = false;
    private $pvpMode = false;
    private $originalBlocks = [];
    private $tickTask = null;
    private $countdownTask = null;
    private $pvpTimerTask = null;

    private $gamestate = 0;

    const WAITING = 0;
    const COUNTDOWN = 1;
    const RUNNING = 2;
    const ENDING = 3;

    public function __construct(Main $plugin, string $name, array $data){
        $this->plugin = $plugin;
        $this->name = $name;
        $this->world = $data["world"];
        $this->spawn = $data["spawn"];
        $this->region = $data["region"] ?? null;
        $this->originalBlocks = $data["blocks"] ?? [];
        $this->spawnPositions = $data["spawn-positions"] ?? [];
        
        // Generate spawn positions if not already set
        if(empty($this->spawnPositions) && $this->region !== null){
            $this->generateSpawnPositions();
        }
    }

    /**
     * Find fall-blocks in the arena region and create spawn positions
     */
    private function generateSpawnPositions(){
        if($this->region === null) return;
        
        $level = $this->plugin->getServer()->getLevelByName($this->world);
        if($level === null) return;
        
        $this->spawnPositions = [];
        $fallBlockIds = [46, 12, 13]; // TNT, Sand, Gravel block IDs
        
        $minX = $this->region["min"][0];
        $maxX = $this->region["max"][0];
        $minY = $this->region["min"][1];
        $maxY = $this->region["max"][1];
        $minZ = $this->region["min"][2];
        $maxZ = $this->region["max"][2];
        
        // Scan region for fall-blocks on the top level
        for($x = $minX; $x <= $maxX; $x++){
            for($z = $minZ; $z <= $maxZ; $z++){
                for($y = $maxY; $y >= $minY; $y--){ // Start from top
                    $block = $level->getBlock(new \pocketmine\math\Vector3($x, $y, $z));
                    
                    if(in_array($block->getId(), $fallBlockIds)){
                        // Check if block above is air (safe to spawn)
                        $blockAbove = $level->getBlock(new \pocketmine\math\Vector3($x, $y + 1, $z));
                        $blockAbove2 = $level->getBlock(new \pocketmine\math\Vector3($x, $y + 2, $z));
                        
                        if($blockAbove->getId() === 0 && $blockAbove2->getId() === 0){ // Air = 0
                            $this->spawnPositions[] = [$x + 0.5, $y + 1, $z + 0.5]; // Center of block + 1 block up
                        }
                        break; // Only take the topmost fall-block in this column
                    }
                }
            }
        }
        
        $this->updateServerStatus(); // AlphaDays
        $this->plugin->getLogger()->info("Generated " . count($this->spawnPositions) . " spawn positions for arena " . $this->name);
    }

    public function getPlugin(): Main {
        return $this->plugin;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getWorldName(): string {
        return $this->world;
    }

    public function isPVPMode(): bool {
        return $this->pvpMode;
    }

    public function addPlayer(Player $player){
        // Check if player is already in the arena
        if(isset($this->alivePlayers[$player->getName()]) || isset($this->spectatorPlayers[$player->getName()])){
            return;
        }

        $this->alivePlayers[$player->getName()] = $player;
        
        // Get the world/level
        $level = $this->plugin->getServer()->getLevelByName($this->world);
        if($level === null){
            $player->sendMessage("§cArena world not loaded!");
            return;
        }

        // Set survival mode and teleport to distributed spawn
        $player->setGamemode(Player::SURVIVAL);
        $spawnPos = $this->getNextSpawnPosition();
        $player->teleport(new Position($spawnPos[0], $spawnPos[1], $spawnPos[2], $level));
        
        // Clear inventory and give basic items
        $this->setupPlayerInventory($player, false);
        
        // Only start countdown if we have enough players
        $minPlayers = $this->plugin->getConfig()->get("min-players-to-start", 2);
        $aliveCount = count($this->alivePlayers);
        
        if($aliveCount >= $minPlayers && !$this->active && $this->countdownTask === null){
            $this->startCountdown();
        } elseif($aliveCount < $minPlayers) {
            $player->sendMessage("§aWaiting for more players... §e(" . $aliveCount . "/" . $minPlayers . ")");
        } else {
            $player->sendMessage("§aWaiting for game to start...");
        }

        // Update arena signs when player joins
        $this->updateServerStatus(); // AlphaDays
        $this->plugin->updateArenaSignsFor($this->name);
    }

    /**
     * Get next spawn position with random distribution on fall-blocks
     */
    private function getNextSpawnPosition(): array {
        if(empty($this->spawnPositions)){
            // Fallback to original spawn if no spawn positions generated
            return $this->spawn;
        }
        
        // Create list of unused spawn positions
        static $usedSpawnIndices = [];
        
        // Reset if all positions have been used
        if(count($usedSpawnIndices) >= count($this->spawnPositions)){
            $usedSpawnIndices = [];
        }
        
        // Find an unused random spawn position
        do {
            $randomIndex = mt_rand(0, count($this->spawnPositions) - 1);
        } while(in_array($randomIndex, $usedSpawnIndices));
        
        // Mark this position as used
        $usedSpawnIndices[] = $randomIndex;
        
        return $this->spawnPositions[$randomIndex];
    }

    private function startCountdown(){
        $this->countdownTask = $this->plugin->getServer()->getScheduler()->scheduleRepeatingTask(
            new WaitingCountdownTask($this), 
            20 // Run every second
        );
        
        $aliveCount = count($this->alivePlayers);
        $maxPlayers = $this->plugin->getConfig()->get("max-players-per-arena", 16);
        $waitTime = $this->plugin->getConfig()->get("wait-time-seconds", 60);
        
        foreach($this->getAllPlayers() as $player){
            if($player->isOnline()){
                if($aliveCount >= $maxPlayers){
                    $player->sendMessage("§aArena is full! Starting countdown...");
                } else {
                    $player->sendMessage("§aWaiting for more players... §7(" . $aliveCount . "/" . $maxPlayers . ")");
                    $player->sendMessage("§eGame will start in " . $waitTime . " seconds or when arena is full.");
                }
                $player->sendMessage("§cYou cannot move until the game starts!");
            }
        }

        $this->setGameState(self::COUNTDOWN);
        $this->updateServerStatus(); // AlphaDays

        // Update arena signs when countdown/waiting starts
        $this->plugin->updateArenaSignsFor($this->name);
    }

    private function setupPlayerInventory(Player $player, bool $isSpectator){
        $player->getInventory()->clearAll();
        
        if($isSpectator && $this->plugin->getConfig()->getNested("spectator.give-leave-item", true)){
            // Give spectator leave item
            $leaveItem = Item::get(Item::BED, 0, 1);
            $player->getInventory()->setItem(0, $leaveItem);
            $player->getInventory()->sendContents($player);
            $player->sendMessage("§eClick the bed in slot 1 to return to hub");
        } elseif(!$isSpectator) {
            // Give feather for speed boost (slot 2, which is index 2) - always give it
            $feather = Item::get(Item::FEATHER, 0, 1);
            $player->getInventory()->setItem(2, $feather);
            $player->getInventory()->sendContents($player);
            $player->sendMessage("§eUse the feather for speed boost!");
        }
    }

    public function activatePVPMode(){
        if($this->pvpMode) return; // Already active
        
        $this->pvpMode = true;
        
        // Cancel PVP timer task since PVP is now active
        if($this->pvpTimerTask !== null){
            $this->pvpTimerTask->cancel();
            $this->pvpTimerTask = null;
        }
        
        // Announce PVP mode activation
        foreach($this->getAllPlayers() as $player){
            if($player->isOnline()){
                $player->sendMessage("§c§l=== PVP MODE ACTIVATED! ===");
                $player->sendMessage("§ePlayers can now attack each other!");
            }
        }
        
        // Give PVP gear to all alive players
        foreach($this->alivePlayers as $player){
            if($player->isOnline()){
                $this->givePVPGear($player);
            }
        }
    }
    
    private function givePVPGear(Player $player){
        // Clear inventory except feather
        $feather = $player->getInventory()->getItem(2); // Save feather from slot 2
        $player->getInventory()->clearAll();
        
        // Give diamond sword in slot 0 (first slot)
        $sword = Item::get(Item::DIAMOND_SWORD, 0, 1);
        $player->getInventory()->setItem(0, $sword);
        
        // Give emerald jump boost item in slot 1 (custom item 383:37)
        $emerald = Item::get(383, 37, 1); // Item ID 383 with damage/meta 37
        $player->getInventory()->setItem(1, $emerald);
        
        // Restore feather in slot 2
        $player->getInventory()->setItem(2, $feather);
        
        // Try to equip armor using older PocketMine methods
        $helmet = Item::get(Item::DIAMOND_HELMET, 0, 1);
        $chestplate = Item::get(Item::DIAMOND_CHESTPLATE, 0, 1);
        $leggings = Item::get(Item::DIAMOND_LEGGINGS, 0, 1);
        $boots = Item::get(Item::DIAMOND_BOOTS, 0, 1);
        
        // Try different armor equipping methods for older PocketMine
        if(method_exists($player, 'getArmorInventory')){
            // Newer PocketMine
            $player->getArmorInventory()->setHelmet($helmet);
            $player->getArmorInventory()->setChestplate($chestplate);
            $player->getArmorInventory()->setLeggings($leggings);
            $player->getArmorInventory()->setBoots($boots);
        } else {
            // Older PocketMine - place in specific inventory slots (corrected order)
            $player->getInventory()->setItem(36, $helmet);    // Helmet
            $player->getInventory()->setItem(37, $chestplate); // Chestplate  
            $player->getInventory()->setItem(38, $leggings);   // Leggings
            $player->getInventory()->setItem(39, $boots);      // Boots
        }
        
        // Force inventory update
        $player->getInventory()->sendContents($player);
        
        $player->sendMessage("§aPVP gear equipped! Fight for survival!");
    }

    public function removePlayer(Player $player, bool $transferToHub = false){
        $wasAlive = isset($this->alivePlayers[$player->getName()]);
        $wasSpectator = isset($this->spectatorPlayers[$player->getName()]);
        
        if(!$wasAlive && !$wasSpectator){
            return; // Player not in this arena
        }
        
        // Remove from both arrays
        unset($this->alivePlayers[$player->getName()]);
        unset($this->spectatorPlayers[$player->getName()]);

        $this->updateServerStatus(); // AlphaDays
        
        // Reset player state
        $player->setGamemode(Player::SURVIVAL);
        $player->getInventory()->clearAll();
        
        // Transfer to hub if requested
        if($transferToHub){
            $this->plugin->transferPlayer(
                $player, 
                $this->plugin->getConfig()->getNested("hub-server.address", ""),
                $this->plugin->getConfig()->getNested("hub-server.port", 19132),
                "§aReturning to hub..."
            );
        }
        
        // Update arena signs when player leaves
        $this->plugin->updateArenaSignsFor($this->name);
        
        // Check if arena should reset (no players left at all)
        if(empty($this->alivePlayers) && empty($this->spectatorPlayers)){
            $this->reset();
            return;
        }
        
        // If game is active and this was an alive player, check win condition
        if($this->active && $wasAlive){
            $this->checkWinCondition();
        }
        
        // If countdown is running but we now have too few alive players, cancel it
        if(!$this->active && $this->countdownTask !== null) {
            $minPlayers = $this->plugin->getConfig()->get("min-players-to-start", 2);
            if(count($this->alivePlayers) < $minPlayers){
                $this->countdownTask->cancel();
                $this->countdownTask = null;
                
                foreach($this->getAllPlayers() as $remainingPlayer){
                    if($remainingPlayer->isOnline()){
                        $remainingPlayer->sendMessage("§eCountdown cancelled - need more players.");
                    }
                }

                // Update arena signs when countdown is cancelled
                $this->plugin->updateArenaSignsFor($this->name);
            }
        }
    }

    public function getAlivePlayers(): array {
        return $this->alivePlayers;
    }

    public function getSpectatorPlayers(): array {
        return $this->spectatorPlayers;
    }

    public function getAllPlayers(): array {
        return array_merge($this->alivePlayers, $this->spectatorPlayers);
    }

    // Legacy method for compatibility
    public function getPlayers(): array {
        return $this->getAllPlayers();
    }

    public function isPlayerAlive(Player $player): bool {
        return isset($this->alivePlayers[$player->getName()]);
    }

    public function isPlayerSpectator(Player $player): bool {
        return isset($this->spectatorPlayers[$player->getName()]);
    }

    public function movePlayerToSpectator(Player $player, string $reason = "eliminated"){
        if(!isset($this->alivePlayers[$player->getName()])){
            return false; // Player not alive in this arena
        }
        
        // Move from alive to spectator
        unset($this->alivePlayers[$player->getName()]);
        $this->spectatorPlayers[$player->getName()] = $player;
        
        // Set spectator mode
        $player->setGamemode(Player::SPECTATOR);
        
        // Clear armor and inventory
        $player->getInventory()->clearAll();
        
        // Move to spectator viewing position
        $spectatorHeight = $this->plugin->getConfig()->getNested("spectator.spectator-height-offset", 10);
        $spectatorPos = new Position(
            $this->spawn[0], 
            $this->spawn[1] + $spectatorHeight, 
            $this->spawn[2], 
            $this->plugin->getServer()->getLevelByName($this->world)
        );
        $player->teleport($spectatorPos);
        
        // Setup spectator inventory
        $this->setupPlayerInventory($player, true);
        
        // Send messages
        $player->sendMessage("§cYou have been eliminated!");
        $player->sendMessage("§eYou are now spectating. Use §b/leave §eor click the bed item to return to hub.");
        
        // Announce to other players
        $aliveCount = count($this->alivePlayers);
        foreach($this->alivePlayers as $alivePlayer){
            if($alivePlayer->isOnline()){
                $alivePlayer->sendMessage("§e" . $player->getName() . " §cwas eliminated! §e(" . $aliveCount . " players remaining)");
            }
        }

        // Update arena signs when player moves to spectator
        $this->plugin->updateArenaSignsFor($this->name);
        
        return true;
    }

    public function setActive(bool $state){
        $this->active = $state;
        
        // Clear countdown task when game becomes active
        if($state && $this->countdownTask !== null){
            $this->countdownTask->cancel();
            $this->countdownTask = null;
        }

        // Start tick task when game becomes active
        if($state && $this->tickTask === null){
            $this->tickTask = $this->plugin->getServer()->getScheduler()->scheduleRepeatingTask(
                new ArenaTickTask($this),
                5
            );
        }

        // Start PVP timer when game becomes active (if PVP is enabled)
        if($state && $this->plugin->getConfig()->getNested("pvp-mode.enabled", true) && $this->pvpTimerTask === null){
            $this->pvpTimerTask = $this->plugin->getServer()->getScheduler()->scheduleRepeatingTask(
                new PVPTimerTask($this),
                20 // Run every second
            );
        }

        // Give feathers to all alive players when game starts
        if($state){
            foreach($this->alivePlayers as $player){
                if($player->isOnline()){
                    $this->setupPlayerInventory($player, false);
                    $player->sendMessage("§aGame started! You can now move and play!");
                }
            }
        }

        $this->setGameState(self::RUNNING);
        $this->updateServerStatus(); // AlphaDays

        // Update arena signs when game state changes
        $this->plugin->updateArenaSignsFor($this->name);
    }

    public function isActive(): bool {
        return $this->active;
    }

    public function tick(){
        if(!$this->active) return;

        $level = $this->plugin->getServer()->getLevelByName($this->world);
        if($level === null) return;

        foreach($this->alivePlayers as $playerName => $player){
            // Check if player is still online
            if(!$player->isOnline()){
                unset($this->alivePlayers[$playerName]);
                continue;
            }

            // Only remove blocks that alive players are standing on
            $playerPos = $player->getPosition();
            $blockUnderPlayer = $level->getBlock(new Vector3(
                floor($playerPos->getX()), 
                floor($playerPos->getY()) - 1, 
                floor($playerPos->getZ())
            ));
            
            // Check if the block should fall
            if(in_array($blockUnderPlayer->getId(), [Block::TNT, Block::SAND, Block::GRAVEL])){
                // Store original block for restoration
                $pos = new Vector3($blockUnderPlayer->getX(), $blockUnderPlayer->getY(), $blockUnderPlayer->getZ());
                $key = $pos->getX() . ":" . $pos->getY() . ":" . $pos->getZ();
                
                if(!isset($this->originalBlocks[$key])){
                    $this->originalBlocks[$key] = $blockUnderPlayer->getId();
                }
                
                // Remove the block
                $level->setBlock($pos, Block::get(Block::AIR));
            }
        }

        // Check win condition less frequently
        static $tickCounter = 0;
        $tickCounter++;
        if($tickCounter % 4 == 0) { // Check every 4 ticks
            $this->checkWinCondition();
        }
    }

    private function checkWinCondition(){
        if(!$this->active) return;
        
        $validAlivePlayers = [];
        $level = $this->plugin->getServer()->getLevelByName($this->world);
        
        if($level === null) return;

        // Get elimination threshold from config
        $baseYLevel = $this->plugin->getConfig()->getNested("elimination.base-y-level", 73);
        $maxFallDistance = $this->plugin->getConfig()->getNested("elimination.max-fall-distance", 5);
        $eliminationY = $baseYLevel - $maxFallDistance;

        // Check each alive player
        foreach($this->alivePlayers as $playerName => $player){
            if($player->isOnline() && $player->getLevel()->getName() === $this->world){
                // Check if player has fallen below elimination threshold
                if($player->getY() > $eliminationY){
                    $validAlivePlayers[] = $player;
                } else {
                    // Player fell below elimination threshold - move to spectator
                    $this->movePlayerToSpectator($player, "fell");
                }
            } else {
                // Player disconnected - remove them
                unset($this->alivePlayers[$playerName]);
            }
        }

        $aliveCount = count($validAlivePlayers);
        
        // Win/end conditions
        if($aliveCount === 1){
            // One winner
            $winner = $validAlivePlayers[0];
            foreach($this->getAllPlayers() as $player){
                if($player->isOnline()){
                    $player->sendMessage("§a" . $winner->getName() . " won the TNT Run!");
                }
            }
            $this->endGame([$winner]);
        } elseif($aliveCount === 0){
            // No winners
            foreach($this->getAllPlayers() as $player){
                if($player->isOnline()){
                    $player->sendMessage("§eNo winner! Everyone fell!");
                }
            }
            $this->endGame([]);
        }
    }

    private function endGame(array $winners = []){
        // Collect all players (alive + spectators) before reset
        $allPlayers = $this->getAllPlayers();
        
        // Reset the arena first
        $this->reset();

        $this->setGameState(self::ENDING);
        $this->updateServerStatus(); // AlphaDays
        
        // Handle post-game player management
        if(!empty($allPlayers)){
            if($this->plugin->getConfig()->getNested("game-end.send-to-spawn", true)){
                $this->sendPlayersToSpawn($allPlayers);
            }
            
            if($this->plugin->getConfig()->getNested("game-end.transfer-to-hub", true)){
                $this->plugin->transferPlayersToHub($allPlayers, count($winners) > 0 ? "game_won" : "game_end");
            } else {
                $this->plugin->kickPlayersAfterGame($allPlayers);
            }
        }
    }
    
    /**
     * Send players to world spawn before transfer/kick
     */
    private function sendPlayersToSpawn(array $players){
        $spawnLevel = $this->plugin->getServer()->getDefaultLevel();
        if($spawnLevel === null) return;
        
        $spawnLocation = $spawnLevel->getSpawnLocation();
        
        foreach($players as $player){
            if($player->isOnline()){
                $player->teleport($spawnLocation);
                $player->sendMessage("§aReturning to spawn...");
            }
        }
    }

    public function reset(){
        $level = $this->plugin->getServer()->getLevelByName($this->world);
        if($level !== null){
            // Restore original blocks
            foreach($this->originalBlocks as $key => $id){
                list($x, $y, $z) = explode(":", $key);
                $level->setBlock(new Vector3($x, $y, $z), Block::get($id));
            }
        }
        
        // Reset all players to survival mode before clearing arrays
        foreach($this->getAllPlayers() as $player){
            if($player->isOnline()){
                $player->setGamemode(Player::SURVIVAL);
                $player->getInventory()->clearAll();
            }
        }
        
        // Reset arena state
        $this->alivePlayers = [];
        $this->spectatorPlayers = [];
        $this->active = false;
        $this->pvpMode = false;
        $this->originalBlocks = [];
        
        // Cancel all running tasks
        if($this->countdownTask !== null){
            $this->countdownTask->cancel();
            $this->countdownTask = null;
        }
        
        if($this->tickTask !== null){
            $this->tickTask->cancel();
            $this->tickTask = null;
        }
        
        if($this->pvpTimerTask !== null){
            $this->pvpTimerTask->cancel();
            $this->pvpTimerTask = null;
        }

        $this->setGameState(self::WAITING);
        $this->updateServerStatus(); // AlphaDays

        // Update arena signs when arena is reset
        $this->plugin->updateArenaSignsFor($this->name);
    }

    /**
     * Save spawn positions when arena setup is complete
     */
    public function saveSpawnPositions(){
        if(!empty($this->spawnPositions)){
            $arenaData = $this->plugin->loadArena($this->name);
            if($arenaData !== null){
                $arenaData["spawn-positions"] = $this->spawnPositions;
                $this->plugin->saveArena($this->name, $arenaData);
            }
        }
    }

    public function getSpawnY(): float {
        return $this->spawn[1];
    }

    public function __destruct(){
        // Cancel tasks when arena is destroyed
        if($this->tickTask !== null){
            $this->tickTask->cancel();
        }
        if($this->countdownTask !== null){
            $this->countdownTask->cancel();
        }
        if($this->pvpTimerTask !== null){
            $this->pvpTimerTask->cancel();
        }
    }

    public function getGameState(): int {
        return $this->gamestate;
    }

    public function setGameState(int $state): void {
        $this->gamestate = $state;
    }

    public function resetGameState(): void {
        $this->gamestate = self::WAITING;
    }

    public function updateServerStatus(): void {
        if ($this->plugin->getStatusUpdater() !== null) {
            $currentPlayers = count($this->getPlayers());
            $maxPlayers = $this->plugin->getConfig()->get("max-players-per-arena", 16);
            $serverAddress = $this->plugin->getConfig()->get("server-address", "localhost");
            $serverPort = $this->plugin->getConfig()->get("server-port", 19132);
            
            $this->plugin->getStatusUpdater()->updateServerStatus(
                $this->getGameState(), 
                $currentPlayers, 
                $maxPlayers, 
                $serverAddress, 
                $serverPort
            );
        }
    }
}
