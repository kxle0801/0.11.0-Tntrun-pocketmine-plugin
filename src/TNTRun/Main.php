<?php
namespace TNTRun;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Player;
use TNTRun\GameManager;
use TNTRun\Listener\DeathListener;
use TNTRun\Listener\RegionSelectionListener;
use TNTRun\Listener\PlayerJoinListener;
use TNTRun\Listener\SpectatorListener;
use TNTRun\Listener\SignListener;
use pocketmine\network\Network;

class Main extends PluginBase {

    /** @var GameManager */
    public $gameManager;

    /** @var SetupSession[] */
    public $setupSessions = [];

    /** @var array */
    public $fallBlocks = [];

    /** @var array */
    private $addressLookupCache = [];

    /** @var SignListener */
    public $signListener;

    public function onEnable(){
        // Ensure writable folder exists
        $this->initializeDirectories();

        // Save default config (copies from resources/config.yml)
        $this->saveDefaultConfig();
        
        $this->fallBlocks = $this->getConfig()->get("fall-blocks", ["tnt", "sand", "gravel"]);
        $this->getLogger()->info("§eFall blocks: " . implode(", ", $this->fallBlocks));

        // Check hub server configuration
        if($this->getConfig()->getNested("hub-server.enabled", true)){
            $hubAddress = $this->getConfig()->getNested("hub-server.address", "");
            if(empty($hubAddress)){
                $this->getLogger()->warning("§cHub server address not configured! Hub transfer disabled.");
            } else {
                $this->getLogger()->info("§aHub transfer enabled to: " . $hubAddress);
            }
        }

        $this->gameManager = new GameManager($this);

        // Register listeners
        $pm = $this->getServer()->getPluginManager();
        $pm->registerEvents(new DeathListener($this), $this);
        $pm->registerEvents(new RegionSelectionListener($this), $this);
        $pm->registerEvents(new PlayerJoinListener($this), $this);
        $pm->registerEvents(new SpectatorListener($this), $this);
        $pm->registerEvents(new \TNTRun\Listener\GameplayListener($this), $this);
        $pm->registerEvents(new \TNTRun\Listener\MovementListener($this), $this);
        
        // Register sign listener with multiple events
        $this->signListener = new SignListener($this);
        $pm->registerEvents($this->signListener, $this);

        // Log current mode
        $editMode = $this->getConfig()->get("edit-mode", false);
        $autoJoin = $this->getConfig()->get("auto-join-enabled", true);
        
        if($editMode) {
            $this->getLogger()->info("§eRunning in EDIT MODE - Auto-join disabled");
        } else if($autoJoin) {
            $autoJoinArena = $this->getConfig()->get("auto-join-arena", "main");
            $this->getLogger()->info("§aAuto-join enabled for arena: " . $autoJoinArena);
        } else {
            $this->getLogger()->info("§eAuto-join disabled - Signs can be used for manual joining");
        }

        // Load arenas count
        $arenaCount = count($this->getAllArenaNames());
        $this->getLogger()->info("§aLoaded " . $arenaCount . " TNT Run arenas.");

        // Disable automatic sign refresh to prevent ghost block issues
        // Signs will update during normal gameplay when players join/leave

        // Schedule periodic sign updates only if we have arenas
        if($arenaCount > 0) {
            $this->getServer()->getScheduler()->scheduleRepeatingTask(
                new class($this) extends \pocketmine\scheduler\PluginTask {
                    public function onRun($currentTick){
                        /** @var Main $plugin */
                        $plugin = $this->getOwner();
                        $plugin->updateAllArenaSigns();
                    }
                },
                100 // Update every 5 seconds (100 ticks)
            );
            $this->getLogger()->info("§aScheduled periodic sign updates.");
        }

        $this->getLogger()->info("§aTNT Run plugin enabled.");
    }

    /**
     * Simple sign refresh - just update existing signs without validation
     */
    public function refreshAllSigns(){
        $signs = $this->getConfig()->get("arena-signs", []);
        $refreshCount = 0;
        
        foreach($signs as $arenaName => $signList){
            foreach($signList as $signData){
                $level = $this->getServer()->getLevelByName($signData["world"]);
                if($level === null) {
                    $this->getLogger()->warning("World " . $signData["world"] . " not loaded for sign refresh");
                    continue;
                }
                
                $block = $level->getBlock(new \pocketmine\math\Vector3($signData["x"], $signData["y"], $signData["z"]));
                $this->getLogger()->info("Sign refresh check at " . $signData["x"] . "," . $signData["y"] . "," . $signData["z"] . " - Block ID: " . $block->getId());
                
                if($block->getId() === 63 || $block->getId() === 68) { // Sign post or wall sign
                    $this->signListener->updateArenaSign($arenaName);
                    $refreshCount++;
                } else {
                    $this->getLogger()->warning("Expected sign but found block ID " . $block->getId() . " at " . $signData["x"] . "," . $signData["y"] . "," . $signData["z"]);
                }
            }
        }
        $this->getLogger()->info("Attempted to refresh " . count($signs) . " arena signs, successfully refreshed " . $refreshCount);
    }

    /**
     * Initialize required directories with proper error handling
     */
    private function initializeDirectories(){
        try {
            // Ensure main data folder exists
            if(!is_dir($this->getDataFolder())){
                if(!mkdir($this->getDataFolder(), 0777, true)){
                    throw new \Exception("Failed to create main data folder");
                }
            }

            // Ensure arenas folder exists
            $arenasDir = $this->getDataFolder() . "arenas/";
            if(!is_dir($arenasDir)){
                if(!mkdir($arenasDir, 0777, true)){
                    throw new \Exception("Failed to create arenas folder");
                }
                // Create a dummy file to test write permissions
                $testFile = $arenasDir . ".test";
                if(file_put_contents($testFile, "test") === false){
                    throw new \Exception("Arenas folder is not writable");
                }
                unlink($testFile);
            }

            $this->getLogger()->info("§aDirectories initialized successfully.");
            
        } catch(\Exception $e) {
            $this->getLogger()->error("§cDirectory initialization failed: " . $e->getMessage());
            $this->getLogger()->error("§cPlugin may not function correctly!");
        }
    }

    /**
     * Update signs for a specific arena using dynamic scanning
     */
    public function updateArenaSignsFor(string $arenaName){
        $this->signListener->updateArenaSign($arenaName);
    }

    /**
     * Update all arena signs by scanning for all arenas
     */
    public function updateAllArenaSigns(){
        $arenaNames = $this->getAllArenaNames();
        foreach($arenaNames as $arenaName){
            $this->signListener->updateArenaSign($arenaName);
        }
    }

    /**
     * Save arena data to separate file
     */
    public function saveArena(string $name, array $data): bool {
        try {
            $arenaDir = $this->getDataFolder() . "arenas/";
            if(!is_dir($arenaDir)){
                mkdir($arenaDir, 0777, true);
            }
            
            $file = $arenaDir . $name . ".json";
            $result = file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
            
            if($result === false) {
                $this->getLogger()->error("Failed to save arena: " . $name);
                return false;
            }
            
            return true;
            
        } catch(\Exception $e) {
            $this->getLogger()->error("Exception saving arena " . $name . ": " . $e->getMessage());
            return false;
        }
    }

    /**
     * Load arena data from file
     */
    public function loadArena(string $name): ?array {
        try {
            $file = $this->getDataFolder() . "arenas/" . $name . ".json";
            if(!file_exists($file)){
                return null;
            }
            
            $content = file_get_contents($file);
            if($content === false) {
                $this->getLogger()->warning("Could not read arena file: " . $name);
                return null;
            }
            
            $data = json_decode($content, true);
            if($data === null) {
                $this->getLogger()->warning("Invalid JSON in arena file: " . $name);
                return null;
            }
            
            return $data;
            
        } catch(\Exception $e) {
            $this->getLogger()->error("Exception loading arena " . $name . ": " . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete arena file
     */
    public function deleteArena(string $name): bool {
        try {
            $file = $this->getDataFolder() . "arenas/" . $name . ".json";
            if(file_exists($file)){
                return unlink($file);
            }
            return true; // File doesn't exist, consider it successfully deleted
            
        } catch(\Exception $e) {
            $this->getLogger()->error("Exception deleting arena " . $name . ": " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all arena names with improved error handling
     */
    public function getAllArenaNames(): array {
        try {
            $arenaDir = $this->getDataFolder() . "arenas/";
            if(!is_dir($arenaDir)){
                return [];
            }
            
            $files = scandir($arenaDir);
            if($files === false) {
                $this->getLogger()->warning("Could not scan arenas directory");
                return [];
            }
            
            $arenas = [];
            
            foreach($files as $file){
                if($file === '.' || $file === '..') {
                    continue;
                }
                
                if(pathinfo($file, PATHINFO_EXTENSION) === "json"){
                    $arenas[] = pathinfo($file, PATHINFO_FILENAME);
                }
            }
            
            return $arenas;
            
        } catch(\Exception $e) {
            $this->getLogger()->error("Exception scanning arenas directory: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if arena exists
     */
    public function arenaExists(string $name): bool {
        $file = $this->getDataFolder() . "arenas/" . $name . ".json";
        return file_exists($file);
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args) : bool {
        switch($command->getName()){
            case "createtnt":
                return $this->handleCreateTNT($sender, $args);
            case "jointnt":
                return $this->handleJoinTNT($sender, $args);
            case "listtnt":
                return $this->handleListTNT($sender, $args);
            case "deltnt":
                return $this->handleDeleteTNT($sender, $args);
            case "tntpos":
                return $this->handleTNTPos($sender, $args);
            case "tntedit":
                return $this->handleTNTEdit($sender, $args);
            case "leave":
                return $this->handleLeave($sender, $args);
            case "signuse":
                return $this->handleSignUse($sender, $args);
            case "signtool":
                return $this->handleSignTool($sender, $args);
        }
        return false;
    }

    private function handleCreateTNT(CommandSender $sender, array $args) : bool {
        if(!$sender instanceof Player){
            $sender->sendMessage("§cThis command can only be used in-game.");
            return true;
        }

        if(count($args) < 1){
            $sender->sendMessage("§eUsage: /createtnt <arenaName>");
            return true;
        }

        $arenaName = strtolower($args[0]);
        
        // Check if arena already exists
        if($this->arenaExists($arenaName)){
            $sender->sendMessage("§cArena §b" . $arenaName . " §calready exists.");
            return true;
        }

        // Check if player is already setting up an arena
        if(isset($this->setupSessions[$sender->getName()])){
            $sender->sendMessage("§cYou are already setting up an arena! Use §e/tntpos cancel §cto cancel.");
            return true;
        }

        // Create basic arena data (region and blocks will be set during selection)
        $arenaData = [
            "world" => $sender->getLevel()->getName(),
            "spawn" => [$sender->getX(), $sender->getY(), $sender->getZ()],
            "region" => null, // Will be set during position selection
            "blocks" => [], // Will store original blocks during position selection
            "fall-blocks" => $this->fallBlocks
        ];
        
        if(!$this->saveArena($arenaName, $arenaData)) {
            $sender->sendMessage("§cFailed to save arena data!");
            return true;
        }
        
        // Create setup session for region selection
        $this->setupSessions[$sender->getName()] = new SetupSession($arenaName);
        
        // Give selection tool
        $axe = \pocketmine\item\Item::get(\pocketmine\item\Item::GOLDEN_AXE, 0, 1);
        $sender->getInventory()->addItem($axe);
        
        $sender->sendMessage("§a" . "=".str_repeat("=", 40));
        $sender->sendMessage("§aArena §b" . $arenaName . " §acreated!");
        $sender->sendMessage("§eNow use the §6Golden Axe §eto select the arena region:");
        $sender->sendMessage("§e• §aLeft click §eor §abreak a block §e= Position 1");
        $sender->sendMessage("§e• §aRight click a block §e= Position 2"); 
        $sender->sendMessage("§eOnce both positions are set, the arena will be ready!");
        $sender->sendMessage("§cUse §e/tntpos cancel §cif you want to cancel setup.");
        $sender->sendMessage("§a" . "=".str_repeat("=", 40));
        return true;
    }

    private function handleLeave(CommandSender $sender, array $args) : bool {
        if(!$sender instanceof Player){
            $sender->sendMessage("§cThis command can only be used in-game.");
            return true;
        }

        // Find which arena the player is in
        $arena = $this->gameManager->getArenaByWorld($sender->getLevel()->getName());
        if($arena === null){
            $sender->sendMessage("§cYou are not in a TNT Run arena.");
            return true;
        }

        $playerName = $sender->getName();
        $isAlive = $arena->isPlayerAlive($sender);
        $isSpectator = $arena->isPlayerSpectator($sender);

        if(!$isAlive && !$isSpectator){
            $sender->sendMessage("§cYou are not participating in this arena.");
            return true;
        }

        // Handle different scenarios
        if($arena->isActive()){
            // Game is active - only spectators can leave (died players)
            if($isSpectator){
                $sender->sendMessage("§aLeaving arena as spectator...");
                $arena->removePlayer($sender, true); // Transfer to hub
            } else {
                $sender->sendMessage("§cYou cannot leave while the game is active! Wait for elimination or game end.");
            }
        } else {
            // Game not active - waiting for players phase
            $sender->sendMessage("§aLeaving arena...");
            
            // Announce to other players
            foreach($arena->getAllPlayers() as $arenaPlayer){
                if($arenaPlayer->getName() !== $sender->getName() && $arenaPlayer->isOnline()){
                    $arenaPlayer->sendMessage("§e" . $sender->getName() . " §cleft the arena.");
                }
            }
            
            // Remove from arena and teleport to spawn (not hub)
            $arena->removePlayer($sender, false); // false = don't transfer to hub
            
            // Teleport to world spawn
            $spawnLevel = $this->getServer()->getDefaultLevel();
            if($spawnLevel !== null){
                $sender->teleport($spawnLevel->getSpawnLocation());
                $sender->sendMessage("§aReturned to spawn.");
            }
        }

        return true;
    }

    private function handleSignTool(CommandSender $sender, array $args) : bool {
        if(!$sender instanceof Player){
            $sender->sendMessage("§cThis command can only be used in-game.");
            return true;
        }

        // Give player a stick to use as sign tool
        $stick = \pocketmine\item\Item::get(\pocketmine\item\Item::STICK, 0, 1);
        $sender->getInventory()->addItem($stick);
        
        $sender->sendMessage("§a" . "=".str_repeat("=", 40));
        $sender->sendMessage("§aSign Tool given!");
        $sender->sendMessage("§eHold the §6stick §eand:");
        $sender->sendMessage("§e• §aRight-click a sign §e= Join arena");
        $sender->sendMessage("§e• §aLeft-click/break a sign §e= Join arena"); 
        $sender->sendMessage("§eThis works just like the golden axe for setup!");
        $sender->sendMessage("§a" . "=".str_repeat("=", 40));
        
        return true;
    }

    private function handleJoinTNT(CommandSender $sender, array $args) : bool {
        if(!$sender instanceof Player){
            $sender->sendMessage("§cThis command can only be used in-game.");
            return true;
        }

        if(count($args) < 1){
            $sender->sendMessage("§eUsage: /jointnt <arenaName>");
            return true;
        }

        $arenaName = strtolower($args[0]);
        return $this->joinPlayerToArena($sender, $arenaName, false);
    }

    private function handleListTNT(CommandSender $sender, array $args) : bool {
        $arenaNames = $this->getAllArenaNames();

        if(empty($arenaNames)){
            $sender->sendMessage("§eNo TNT Run arenas have been created yet.");
            $sender->sendMessage("§eUse §b/createtnt <name> §eto create one!");
            return true;
        }

        $sender->sendMessage("§a" . "=".str_repeat("=", 30));
        $sender->sendMessage("§aAvailable TNT Run arenas:");
        
        foreach($arenaNames as $name){
            $arenaData = $this->loadArena($name);
            $status = "§c✗ Incomplete"; // Default status
            $players = "§80";
            
            if($arenaData !== null && $arenaData["region"] !== null){
                $arena = $this->gameManager->getArena($name);
                if($arena !== null){
                    if($arena->isActive()){
                        $status = "§e⚡ Active";
                    } else {
                        $status = "§a✓ Ready";
                    }
                    $playerCount = count($arena->getAllPlayers());
                    $aliveCount = count($arena->getAlivePlayers());
                    $spectatorCount = count($arena->getSpectatorPlayers());
                    
                    if($spectatorCount > 0){
                        $players = "§b" . $aliveCount . "§7+§8" . $spectatorCount;
                    } else {
                        $players = "§b" . $aliveCount;
                    }
                } else {
                    $status = "§a✓ Ready";
                }
            }
            
            $sender->sendMessage("§b• " . $name . " §7- " . $status . " §7(" . $players . " players)");
        }
        
        $sender->sendMessage("§a" . "=".str_repeat("=", 30));
        return true;
    }

    private function handleDeleteTNT(CommandSender $sender, array $args) : bool {
        if(!$sender instanceof Player){
            $sender->sendMessage("§cThis command can only be used in-game.");
            return true;
        }

        if(count($args) < 1){
            $sender->sendMessage("§eUsage: /deltnt <arenaName>");
            return true;
        }

        $arenaName = strtolower($args[0]);

        if(!$this->arenaExists($arenaName)){
            $sender->sendMessage("§cArena §b" . $arenaName . " §cdoes not exist.");
            return true;
        }

        if(!$this->deleteArena($arenaName)) {
            $sender->sendMessage("§cFailed to delete arena!");
            return true;
        }
        
        // Reload arenas
        $this->gameManager->loadArenas();

        $sender->sendMessage("§aArena §b" . $arenaName . " §ahas been deleted.");
        return true;
    }

    private function handleTNTPos(CommandSender $sender, array $args) : bool {
        if(!$sender instanceof Player){
            $sender->sendMessage("§cThis command can only be used in-game.");
            return true;
        }

        $session = $this->setupSessions[$sender->getName()] ?? null;
        if($session === null){
            $sender->sendMessage("§cYou are not setting up an arena.");
            return true;
        }

        if(count($args) < 1){
            $sender->sendMessage("§eUsage: /tntpos <cancel|status>");
            return true;
        }

        switch(strtolower($args[0])){
            case "cancel":
                // Get the arena name before removing session
                $arenaName = $session->arenaName;
                
                // Remove golden axe
                $inventory = $sender->getInventory();
                for($i = 0; $i < $inventory->getSize(); $i++){
                    $item = $inventory->getItem($i);
                    if($item->getId() === \pocketmine\item\Item::GOLDEN_AXE){
                        $inventory->setItem($i, \pocketmine\item\Item::get(\pocketmine\item\Item::AIR));
                        break;
                    }
                }
                
                // Delete the incomplete arena file
                $this->deleteArena($arenaName);
                
                // Remove setup session
                unset($this->setupSessions[$sender->getName()]);
                
                // Reload arenas to remove it from game manager
                $this->gameManager->loadArenas();
                
                $sender->sendMessage("§cArena setup cancelled and incomplete arena deleted.");
                break;
                
            case "status":
                $pos1 = $session->pos1 ? $session->pos1->getX() . ", " . $session->pos1->getY() . ", " . $session->pos1->getZ() : "Not set";
                $pos2 = $session->pos2 ? $session->pos2->getX() . ", " . $session->pos2->getY() . ", " . $session->pos2->getZ() : "Not set";
                $sender->sendMessage("§eArena: §b" . $session->arenaName);
                $sender->sendMessage("§ePosition 1: §b" . $pos1);
                $sender->sendMessage("§ePosition 2: §b" . $pos2);
                break;
                
            default:
                $sender->sendMessage("§eUsage: /tntpos <cancel|status>");
        }
        
        return true;
    }

    private function handleTNTEdit(CommandSender $sender, array $args) : bool {
        if(!$sender->hasPermission("tntrun.edit")){
            $sender->sendMessage("§cYou don't have permission to use this command.");
            return true;
        }

        if(count($args) < 1){
            $editMode = $this->getConfig()->get("edit-mode", false);
            $sender->sendMessage("§eEdit mode is currently: " . ($editMode ? "§aENABLED" : "§cDISABLED"));
            $sender->sendMessage("§eUsage: /tntedit <on|off>");
            return true;
        }

        switch(strtolower($args[0])){
            case "on":
            case "true":
            case "enable":
                $this->getConfig()->set("edit-mode", true);
                $this->getConfig()->save();
                $sender->sendMessage("§aEdit mode ENABLED. Players will not auto-join arenas.");
                $this->getLogger()->info("Edit mode enabled by " . $sender->getName());
                break;
                
            case "off":
            case "false":
            case "disable":
                $this->getConfig()->set("edit-mode", false);
                $this->getConfig()->save();
                $sender->sendMessage("§cEdit mode DISABLED. Auto-join resumed.");
                $this->getLogger()->info("Edit mode disabled by " . $sender->getName());
                break;
                
            default:
                $sender->sendMessage("§eUsage: /tntedit <on|off>");
        }
        
        return true;
    }

    private function handleSignUse(CommandSender $sender, array $args) : bool {
        if(!$sender instanceof Player){
            $sender->sendMessage("§cThis command can only be used in-game.");
            return true;
        }

        $player = $sender;
        $level = $player->getLevel();
        $pos = $player->getPosition();
        
        // Look for arena signs near the player
        $foundSigns = [];
        
        for($x = -3; $x <= 3; $x++){
            for($y = -2; $y <= 2; $y++){
                for($z = -3; $z <= 3; $z++){
                    $checkPos = $pos->add($x, $y, $z);
                    $block = $level->getBlock($checkPos);
                    
                    if($block->getId() === \pocketmine\block\Block::SIGN_POST || $block->getId() === \pocketmine\block\Block::WALL_SIGN){
                        $tile = $level->getTile($block);
                        if($tile instanceof \pocketmine\tile\Sign){
                            $lines = $tile->getText();
                            $firstLine = strtolower(trim(preg_replace('/§[0-9a-fk-or]/', '', $lines[0])));
                            
                            if($firstLine === "[arena]"){
                                $arenaName = strtolower(trim(preg_replace('/§[0-9a-fk-or]/', '', $lines[1])));
                                if(!empty($arenaName)){
                                    $foundSigns[] = $arenaName;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        if(empty($foundSigns)){
            $player->sendMessage("§cNo arena signs found nearby. Get closer to a sign first!");
            return true;
        }
        
        if(count($foundSigns) === 1){
            $arenaName = $foundSigns[0];
            $player->sendMessage("§aTrying to join arena: " . $arenaName);
            
            $success = $this->joinPlayerToArena($player, $arenaName, false);
            if(!$success){
                $player->sendMessage("§cCould not join arena.");
            }
        } else {
            $player->sendMessage("§eMultiple arena signs found:");
            foreach($foundSigns as $arena){
                $player->sendMessage("§b- " . $arena);
            }
            $player->sendMessage("§eUse §b/jointnt <arena> §eto join a specific one.");
        }
        
        return true;
    }

    /**
     * Join a player to an arena with proper validation
     */
    public function joinPlayerToArena(Player $player, string $arenaName, bool $isAutoJoin = false): bool {
        // Check if arena exists
        if(!$this->arenaExists($arenaName)){
            if(!$isAutoJoin) {
                $player->sendMessage("§cArena §b" . $arenaName . " §cdoes not exist.");
            }
            return false;
        }
        
        // Load arena data
        $arenaData = $this->loadArena($arenaName);
        if($arenaData === null){
            if(!$isAutoJoin) {
                $player->sendMessage("§cFailed to load arena §b" . $arenaName . "§c.");
            }
            return false;
        }
        
        // Check if arena setup is complete (has region)
        if($arenaData["region"] === null){
            if(!$isAutoJoin) {
                $player->sendMessage("§cArena §b" . $arenaName . " §csetup is not complete! The region hasn't been set yet.");
            }
            return false;
        }

        $arena = $this->gameManager->getArena($arenaName);
        if($arena === null){
            if(!$isAutoJoin) {
                $player->sendMessage("§cArena §b" . $arenaName . " §cis not loaded properly. Try reloading the plugin.");
            }
            return false;
        }

        if($arena->isActive()){
            if(!$isAutoJoin) {
                $player->sendMessage("§cArena §b" . $arenaName . " §cis currently active.");
            }
            return false;
        }

        // Check if player is already in this arena
        $allPlayers = $arena->getAllPlayers();
        if(isset($allPlayers[$player->getName()])){
            if(!$isAutoJoin) {
                $player->sendMessage("§eYou are already in arena §b" . $arenaName . "§e.");
            }
            return false;
        }

        // Check max players
        $maxPlayers = $this->getConfig()->get("max-players-per-arena", 16);
        if(count($allPlayers) >= $maxPlayers){
            if(!$isAutoJoin) {
                $player->sendMessage("§cArena §b" . $arenaName . " §cis full!");
            }
            return false;
        }

        $arena->addPlayer($player);
        
        if($isAutoJoin) {
            $player->sendMessage("§aWelcome! You've been placed in TNT Run arena §b" . $arenaName . "§a!");
        } else {
            $player->sendMessage("§aJoined TNT Run arena §b" . $arenaName . "§a!");
        }
        
        // Show arena info
        $playerCount = count($arena->getAllPlayers());
        foreach($arena->getAllPlayers() as $arenaPlayer){
            if($arenaPlayer->getName() !== $player->getName()){
                $arenaPlayer->sendMessage("§e" . $player->getName() . " §ajoined the arena! §e(" . $playerCount . " players)");
            }
        }
        
        // Update arena signs when player joins
        $this->updateArenaSignsFor($arenaName);
        
        return true;
    }

    /**
     * Transfer players back to hub server with staggered delays
     */
    public function transferPlayersToHub(array $players, string $reason = "game_end"): void {
        $hubConfig = $this->getConfig()->get("hub-server", []);
        
        if(!($hubConfig["enabled"] ?? true)){
            return;
        }

        $address = $hubConfig["address"] ?? "";
        if(empty($address)){
            $this->getLogger()->warning("Hub transfer failed: No address configured");
            return;
        }

        $port = $hubConfig["port"] ?? 19132;
        $message = $hubConfig["transfer-message"] ?? "§aReturning to hub...";
        $baseDelay = $hubConfig["transfer-delay"] ?? 3;
        $staggerDelay = $hubConfig["staggered-transfer-delay"] ?? 2;

        // Transfer players with staggered delays
        $playerIndex = 0;
        foreach($players as $player){
            if($player->isOnline()){
                $individualDelay = $baseDelay + ($playerIndex * $staggerDelay);
                
                $this->getServer()->getScheduler()->scheduleDelayedTask(new class($this, $player, $address, $port, $message) extends \pocketmine\scheduler\PluginTask {
                    private $player;
                    private $address;
                    private $port;
                    private $message;

                    public function __construct(Main $plugin, \pocketmine\Player $player, string $address, int $port, string $message){
                        parent::__construct($plugin);
                        $this->player = $player;
                        $this->address = $address;
                        $this->port = $port;
                        $this->message = $message;
                    }

                    public function onRun($currentTick){
                        if($this->player->isOnline()){
                            $plugin = $this->getOwner();
                            $plugin->transferPlayer($this->player, $this->address, $this->port, $this->message);
                        }
                    }
                }, $individualDelay * 20);
                
                $playerIndex++;
            }
        }
    }

    /**
     * Kick players from server after game ends (alternative to hub transfer)
     */
    public function kickPlayersAfterGame(array $players): void {
        $kickMessage = $this->getConfig()->getNested("game-end.kick-message", "§cGame ended! Thanks for playing!");
        $baseDelay = $this->getConfig()->getNested("hub-server.transfer-delay", 3);
        $staggerDelay = $this->getConfig()->getNested("hub-server.staggered-transfer-delay", 2);

        // Kick players with staggered delays
        $playerIndex = 0;
        foreach($players as $player){
            if($player->isOnline()){
                $individualDelay = $baseDelay + ($playerIndex * $staggerDelay);
                
                $this->getServer()->getScheduler()->scheduleDelayedTask(new class($this, $player, $kickMessage) extends \pocketmine\scheduler\PluginTask {
                    private $player;
                    private $kickMessage;

                    public function __construct(Main $plugin, \pocketmine\Player $player, string $kickMessage){
                        parent::__construct($plugin);
                        $this->player = $player;
                        $this->kickMessage = $kickMessage;
                    }

                    public function onRun($currentTick){
                        if($this->player->isOnline()){
                            $this->player->kick($this->kickMessage, false);
                        }
                    }
                }, $individualDelay * 20);
                
                $playerIndex++;
            }
        }
    }

    /**
     * Transfer a player to another server
     */
    public function transferPlayer(Player $player, string $address, int $port = 19132, string $message = "You are being transferred"): bool {
        $ip = $this->lookupAddress($address);
        if($ip === null){
            $this->getLogger()->warning("Failed to resolve address: " . $address);
            return false;
        }
        
        if($message !== null && $message !== ""){
            $player->sendMessage($message);
        }

        $packet = new TransferPacket();
        $packet->address = $ip;
        $packet->port = $port;
        $player->dataPacket($packet->setChannel(Network::CHANNEL_PRIORITY));

        return true;
    }

    /**
     * Resolve domain names to IP addresses with caching
     */
    private function lookupAddress(string $address): ?string {
        if(preg_match("/^[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}$/", $address) > 0){
            return $address;
        }

        $address = strtolower($address);

        if(isset($this->addressLookupCache[$address])){
            return $this->addressLookupCache[$address];
        }

        $host = gethostbyname($address);
        if($host === $address){
            return null;
        }

        $this->addressLookupCache[$address] = $host;
        return $host;
    }

    public function clearLookupCache(): void {
        $this->addressLookupCache = [];
    }

    public function onDisable(){
        $this->getLogger()->info("§cTNT Run plugin disabled.");
    }
}