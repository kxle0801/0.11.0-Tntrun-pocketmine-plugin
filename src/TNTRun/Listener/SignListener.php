<?php
namespace TNTRun\Listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\tile\Sign;
use pocketmine\Player;
use pocketmine\block\Block;
use pocketmine\item\Item;
use TNTRun\Main;

class SignListener implements Listener {

    private $plugin;

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
    }

    /**
     * Handle sign creation
     */
    public function onSignChange(SignChangeEvent $event){
        $player = $event->getPlayer();
        $lines = $event->getLines();
        
        $this->plugin->getLogger()->info("SignChange event: Player " . $player->getName() . " - Lines: " . implode(" | ", $lines));
        
        // Check if first line is [arena] (case insensitive)
        if(strtolower(trim($lines[0])) !== "[arena]"){
            $this->plugin->getLogger()->info("Not an arena sign, first line: '" . $lines[0] . "' (lowercase: '" . strtolower(trim($lines[0])) . "')");
            return;
        }
        
        $this->plugin->getLogger()->info("Processing arena sign creation");
        
        // Rest of the existing code...
        
        // Check permission to create arena signs
        if(!$player->hasPermission("tntrun.create")){
            $player->sendMessage("§cYou don't have permission to create arena signs.");
            $event->setCancelled();
            return;
        }
        
        // Validate arena name
        $arenaName = strtolower(trim($lines[1]));
        if(empty($arenaName)){
            $player->sendMessage("§cArena name cannot be empty on line 2.");
            $event->setCancelled();
            return;
        }
        
        // Check if arena exists
        if(!$this->plugin->arenaExists($arenaName)){
            $player->sendMessage("§cArena '§b" . $arenaName . "§c' does not exist.");
            $event->setCancelled();
            return;
        }
        
        // Load arena to verify it's complete
        $arenaData = $this->plugin->loadArena($arenaName);
        if($arenaData === null || $arenaData["region"] === null){
            $player->sendMessage("§cArena '§b" . $arenaName . "§c' is not complete. Finish setup first.");
            $event->setCancelled();
            return;
        }
        
        // Store the sign location for this arena - DISABLED to prevent issues
        // $this->addArenaSign($arenaName, $event->getBlock());
        
        // Update sign with proper formatting
        $this->updateSignText($event, $arenaName);
        
        $player->sendMessage("§aArena sign created for '§b" . $arenaName . "§a'!");
    }
    
    /**
     * Handle sign interaction (clicking to join)
     */
    public function onInteract(PlayerInteractEvent $event){
        $player = $event->getPlayer();
        $item = $player->getInventory()->getItemInHand();
        $block = $event->getBlock();
        
        // Check if it's a sign
        if(!$this->isSign($block)){
            return;
        }

        // Check if player has empty hand (AIR)
        if($item->getId() !== Item::AIR && $item->getId() !== 0){
            return;
        }

        $event->setCancelled();
        
        $tile = $block->getLevel()->getTile($block);
        if(!($tile instanceof Sign)){
            return;
        }

        $lines = $tile->getText();
        
        // Check if it's an arena sign
        $firstLine = strtolower(trim(preg_replace('/§[0-9a-fk-or]/', '', $lines[0])));
        if($firstLine !== "[arena]"){
            return;
        }
        
        // Extract arena name
        $arenaName = strtolower(trim(preg_replace('/§[0-9a-fk-or]/', '', $lines[1])));
        if(empty($arenaName)){
            $player->sendMessage("§cInvalid arena sign - no arena name found.");
            return;
        }
        
        // Check if edit mode is active
        if($this->plugin->getConfig()->get("edit-mode", false)){
            $player->sendMessage("§eEdit mode is active. Sign joining disabled.");
            return;
        }
        
        // Try to join the arena
        $success = $this->plugin->joinPlayerToArena($player, $arenaName, false);
        
        if(!$success){
            $player->sendMessage("§cCould not join arena '§b" . $arenaName . "§c'.");
        }
    }
    
    /**
     * Handle sign breaking
     */
    public function onBlockBreak(BlockBreakEvent $event){
        $block = $event->getBlock();
        $player = $event->getPlayer();
        
        // Check if it's a sign
        if(!$this->isSign($block)){
            return;
        }
        
        $tile = $block->getLevel()->getTile($block);
        if(!($tile instanceof Sign)){
            return;
        }
        
        $lines = $tile->getText();
        
        // Check if it's an arena sign
        $firstLine = strtolower(trim(preg_replace('/§[0-9a-fk-or]/', '', $lines[0])));
        if($firstLine !== "[arena]"){
            return;
        }
        
        // Check permission to break arena signs
        if(!$player->hasPermission("tntrun.create")){
            $player->sendMessage("§cYou don't have permission to break arena signs.");
            $event->setCancelled();
            return;
        }
        
        // Remove sign from storage - DISABLED since we're not storing signs in config
        // $arenaName = strtolower(trim(preg_replace('/§[0-9a-fk-or]/', '', $lines[1])));
        // $this->removeArenaSign($arenaName, $block);
        
        $player->sendMessage("§aArena sign removed.");
    }
    
    /**
     * Update sign text with current arena status
     */
    private function updateSignText(SignChangeEvent $event, string $arenaName){
        $arena = $this->plugin->gameManager->getArena($arenaName);
        $maxPlayers = $this->plugin->getConfig()->get("max-players-per-arena", 16);
        
        $playerCount = 0;
        $status = "§cOffline";
        
        if($arena !== null){
            $playerCount = count($arena->getAllPlayers());
            
            if($arena->isActive()){
                $status = "§eActive";
            } else {
                $minPlayers = $this->plugin->getConfig()->get("min-players-to-start", 2);
                if($playerCount >= $minPlayers){
                    $status = "§aStarting";
                } else {
                    $status = "§bWaiting";
                }
            }
        }
        
        // Set the sign lines
        $event->setLine(0, "§8[§bArena§8]");
        $event->setLine(1, "§b" . $arenaName);
        $event->setLine(2, "§7" . $playerCount . "§8/§7" . $maxPlayers);
        $event->setLine(3, $status);
    }
    
    /**
     * Update all signs for a specific arena by scanning loaded worlds
     */
    public function updateArenaSign(string $arenaName){
        $arena = $this->plugin->gameManager->getArena($arenaName);
        $maxPlayers = $this->plugin->getConfig()->get("max-players-per-arena", 16);
        
        $playerCount = 0;
        $status = "§cOffline";
        
        if($arena !== null){
            $playerCount = count($arena->getAllPlayers());
            
            if($arena->isActive()){
                $status = "§eActive";
            } else {
                $minPlayers = $this->plugin->getConfig()->get("min-players-to-start", 2);
                if($playerCount >= $minPlayers){
                    $status = "§aStarting";
                } else {
                    $status = "§bWaiting";
                }
            }
        }
        
        // Scan all loaded worlds for arena signs
        foreach($this->plugin->getServer()->getLevels() as $level){
            foreach($level->getTiles() as $tile){
                if($tile instanceof Sign){
                    $lines = $tile->getText();
                    $firstLine = strtolower(trim(preg_replace('/§[0-9a-fk-or]/', '', $lines[0])));
                    $secondLine = strtolower(trim(preg_replace('/§[0-9a-fk-or]/', '', $lines[1])));
                    
                    // Check if this is a sign for the specified arena
                    if($firstLine === "[arena]" && $secondLine === $arenaName){
                        $tile->setText(
                            "§8[§bArena§8]",
                            "§b" . $arenaName,
                            "§7" . $playerCount . "§8/§7" . $maxPlayers,
                            $status
                        );
                    }
                }
            }
        }
    }
    
    /**
     * Check if block is a sign
     */
    private function isSign(Block $block): bool {
        return $block->getId() === Block::SIGN_POST || $block->getId() === Block::WALL_SIGN;
    }
    
    /**
     * Add arena sign to storage
     */
    private function addArenaSign(string $arenaName, Block $block){
        $signs = $this->plugin->getConfig()->get("arena-signs", []);
        
        if(!isset($signs[$arenaName])){
            $signs[$arenaName] = [];
        }
        
        $signKey = $block->getLevel()->getName() . ":" . $block->getX() . ":" . $block->getY() . ":" . $block->getZ();
        $signs[$arenaName][$signKey] = [
            "world" => $block->getLevel()->getName(),
            "x" => $block->getX(),
            "y" => $block->getY(),
            "z" => $block->getZ()
        ];
        
        $this->plugin->getConfig()->set("arena-signs", $signs);
        $this->plugin->getConfig()->save();
    }
    
    /**
     * Remove arena sign from storage
     */
    private function removeArenaSign(string $arenaName, Block $block){
        $signs = $this->plugin->getConfig()->get("arena-signs", []);
        
        if(!isset($signs[$arenaName])){
            return;
        }
        
        $signKey = $block->getLevel()->getName() . ":" . $block->getX() . ":" . $block->getY() . ":" . $block->getZ();
        unset($signs[$arenaName][$signKey]);
        
        // Remove arena entry if no signs left
        if(empty($signs[$arenaName])){
            unset($signs[$arenaName]);
        }
        
        $this->plugin->getConfig()->set("arena-signs", $signs);
        $this->plugin->getConfig()->save();
    }
    
    /**
     * Get all signs for an arena
     */
    private function getArenaSigns(string $arenaName): array {
        $signs = $this->plugin->getConfig()->get("arena-signs", []);
        return $signs[$arenaName] ?? [];
    }
}