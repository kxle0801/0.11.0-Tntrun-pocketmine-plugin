<?php
namespace TNTRun\Listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\item\Item;
use pocketmine\Player;
use TNTRun\Main;

class RegionSelectionListener implements Listener {

    private $plugin;

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
    }

    public function onInteract(PlayerInteractEvent $event){
        $player = $event->getPlayer();
        $item = $player->getInventory()->getItemInHand();
        
        // Check if player has setup session
        $session = $this->plugin->setupSessions[$player->getName()] ?? null;
        if($session === null) return;
        
        // Check if holding a golden axe and has setup session
        if($item->getId() !== Item::GOLDEN_AXE){
            return;
        }
        
        $event->setCancelled(); // Prevent normal axe behavior
        
        $block = $event->getBlock();
        $pos = new \pocketmine\math\Vector3($block->getX(), $block->getY(), $block->getZ());
        
        if($event->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK){
            // Left click = Position 1
            $session->pos1 = $pos;
            $player->sendMessage("§aPosition 1 set: §e" . $pos->getX() . ", " . $pos->getY() . ", " . $pos->getZ());
            
        } elseif($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK){
            // Right click = Position 2
            $session->pos2 = $pos;
            $player->sendMessage("§aPosition 2 set: §e" . $pos->getX() . ", " . $pos->getY() . ", " . $pos->getZ());
        }
        
        // Check if selection is complete after setting either position
        if($session->isComplete()){
            $player->sendMessage("§aBoth positions set! Completing arena setup...");
            $this->completeSelection($player, $session);
        } else {
            if($session->pos1 !== null && $session->pos2 === null){
                $player->sendMessage("§eNow right-click a block to set position 2!");
            } elseif($session->pos1 === null && $session->pos2 !== null){
                $player->sendMessage("§eNow left-click a block to set position 1!");
            }
        }
    }
    
    public function onBlockBreak(BlockBreakEvent $event){
        $player = $event->getPlayer();
        $item = $player->getInventory()->getItemInHand();
        
        // Prevent breaking blocks with golden axe when in setup mode
        if($item->getId() === Item::GOLDEN_AXE && isset($this->plugin->setupSessions[$player->getName()])){
            $event->setCancelled();
            
            // Treat as left click for position 1
            $session = $this->plugin->setupSessions[$player->getName()] ?? null;
            if($session !== null){
                $block = $event->getBlock();
                $pos = new \pocketmine\math\Vector3($block->getX(), $block->getY(), $block->getZ());
                $session->pos1 = $pos;
                $player->sendMessage("§aPosition 1 set: §e" . $pos->getX() . ", " . $pos->getY() . ", " . $pos->getZ());
                
                // Check completion after setting pos1 via block break
                if($session->isComplete()){
                    $player->sendMessage("§aBoth positions set! Completing arena setup...");
                    $this->completeSelection($player, $session);
                } else {
                    $player->sendMessage("§eNow right-click a block to set position 2!");
                }
            }
        }
    }
    
    private function completeSelection(Player $player, $session){
        $arenaName = $session->arenaName;
        
        $player->sendMessage("§eProcessing region selection...");
        
        // Calculate region bounds
        $minX = min($session->pos1->getX(), $session->pos2->getX());
        $maxX = max($session->pos1->getX(), $session->pos2->getX());
        $minY = min($session->pos1->getY(), $session->pos2->getY());
        $maxY = max($session->pos1->getY(), $session->pos2->getY());
        $minZ = min($session->pos1->getZ(), $session->pos2->getZ());
        $maxZ = max($session->pos1->getZ(), $session->pos2->getZ());
        
        $player->sendMessage("§eCalculated region bounds: " . $minX . "," . $minY . "," . $minZ . " to " . $maxX . "," . $maxY . "," . $maxZ);
        
        // Load existing arena data
        $arenaData = $this->plugin->loadArena($arenaName);
        if($arenaData === null){
            $player->sendMessage("§cError: Arena data not found!");
            return;
        }
        
        // Update arena data with region
        $arenaData["region"] = [
            "min" => [$minX, $minY, $minZ],
            "max" => [$maxX, $maxY, $maxZ]
        ];
        
        $player->sendMessage("§eSaving blocks in region...");
        
        // Store all blocks in the region for restoration
        $blocks = [];
        $level = $player->getLevel();
        $blockCount = 0;
        
        for($x = $minX; $x <= $maxX; $x++){
            for($y = $minY; $y <= $maxY; $y++){
                for($z = $minZ; $z <= $maxZ; $z++){
                    $block = $level->getBlock(new \pocketmine\math\Vector3($x, $y, $z));
                    $key = $x . ":" . $y . ":" . $z;
                    $blocks[$key] = $block->getId();
                    $blockCount++;
                }
            }
        }
        
        $arenaData["blocks"] = $blocks;
        
        // Generate spawn positions on fall-blocks
        $player->sendMessage("§eGenerating spawn positions on fall-blocks...");
        $spawnPositions = [];
        $fallBlockIds = [46, 12, 13]; // TNT, Sand, Gravel block IDs
        
        for($x = $minX; $x <= $maxX; $x++){
            for($z = $minZ; $z <= $maxZ; $z++){
                for($y = $maxY; $y >= $minY; $y--){ // Start from top
                    $block = $level->getBlock(new \pocketmine\math\Vector3($x, $y, $z));
                    
                    if(in_array($block->getId(), $fallBlockIds)){
                        // Check if block above is air (safe to spawn)
                        $blockAbove = $level->getBlock(new \pocketmine\math\Vector3($x, $y + 1, $z));
                        $blockAbove2 = $level->getBlock(new \pocketmine\math\Vector3($x, $y + 2, $z));
                        
                        if($blockAbove->getId() === 0 && $blockAbove2->getId() === 0){ // Air = 0
                            $spawnPositions[] = [$x + 0.5, $y + 1, $z + 0.5]; // Center of block + 1 block up
                        }
                        break; // Only take the topmost fall-block in this column
                    }
                }
            }
        }
        
        $arenaData["spawn-positions"] = $spawnPositions;
        
        // Save updated arena data
        $this->plugin->saveArena($arenaName, $arenaData);
        
        $player->sendMessage("§aSaved " . $blockCount . " blocks to arena file.");
        $player->sendMessage("§aGenerated " . count($spawnPositions) . " spawn positions on fall-blocks.");
        
        // Reload arenas
        $this->plugin->gameManager->loadArenas();
        
        $player->sendMessage("§a" . "=".str_repeat("=", 30));
        $player->sendMessage("§aRegion selection complete!");
        $player->sendMessage("§eArena: §b" . $arenaName);
        $player->sendMessage("§eRegion size: §b" . ($maxX - $minX + 1) . "x" . ($maxY - $minY + 1) . "x" . ($maxZ - $minZ + 1) . " §eblocks");
        $player->sendMessage("§eBlocks saved: §b" . $blockCount);
        $player->sendMessage("§eSpawn positions: §b" . count($spawnPositions));
        $player->sendMessage("§aArena §b" . $arenaName . " §ais now ready to use!");
        $player->sendMessage("§eUse §b/jointnt " . $arenaName . " §eto test it!");
        $player->sendMessage("§a" . "=".str_repeat("=", 30));
        
        // Remove golden axe from inventory
        $inventory = $player->getInventory();
        for($i = 0; $i < $inventory->getSize(); $i++){
            $item = $inventory->getItem($i);
            if($item->getId() === Item::GOLDEN_AXE){
                $inventory->setItem($i, Item::get(Item::AIR));
                $player->sendMessage("§aSelection tool removed from inventory.");
                break;
            }
        }
        
        // Remove setup session
        unset($this->plugin->setupSessions[$player->getName()]);
        $player->sendMessage("§aSetup session ended.");
    }
}