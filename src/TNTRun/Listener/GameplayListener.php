<?php
namespace TNTRun\Listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\item\Item;
use pocketmine\entity\Effect;
use TNTRun\Main;

class GameplayListener implements Listener {

    private $plugin;
    private $speedCooldowns = []; // Track per-player speed cooldowns
    private $jumpCooldowns = [];  // Track per-player jump cooldowns

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
    }

    public function onInteract(PlayerInteractEvent $event){
        $player = $event->getPlayer();
        $item = $player->getInventory()->getItemInHand();
        
        // Handle feather item for speed boost
        if($item->getId() === Item::FEATHER){
            $arena = $this->plugin->gameManager->getArenaByWorld($player->getLevel()->getName());
            if($arena !== null && $arena->isPlayerAlive($player)){
                $event->setCancelled();
                
                $playerName = $player->getName();
                $currentTime = time();
                $cooldownSeconds = $this->plugin->getConfig()->getNested("speed-boost.cooldown-seconds", 20);
                
                // Check cooldown
                if(isset($this->speedCooldowns[$playerName])){
                    $timeLeft = ($this->speedCooldowns[$playerName] + $cooldownSeconds) - $currentTime;
                    if($timeLeft > 0){
                        $player->sendMessage("§cSpeed boost on cooldown! Wait " . $timeLeft . " seconds.");
                        return;
                    }
                }
                
                // Apply speed boost
                $duration = $this->plugin->getConfig()->getNested("speed-boost.duration-seconds", 7);
                $effect = Effect::getEffect(1); // Effect ID 1 = Speed
                $effect->setDuration($duration * 20); // Convert seconds to ticks
                $effect->setAmplifier(1); // Amplifier 1 = Speed II
                $player->addEffect($effect);
                
                // Set cooldown
                $this->speedCooldowns[$playerName] = $currentTime;
                
                $player->sendMessage("§aSpeed boost activated for " . $duration . " seconds!");
                return;
            }
        }
        
        // Handle emerald item for jump boost (PVP mode only) - Updated item ID
        if($item->getId() === 383 && $item->getDamage() === 37){
            $arena = $this->plugin->gameManager->getArenaByWorld($player->getLevel()->getName());
            if($arena !== null && $arena->isPlayerAlive($player) && $arena->isPVPMode()){
                $event->setCancelled();
                
                $playerName = $player->getName();
                $currentTime = time();
                $cooldownMinutes = $this->plugin->getConfig()->getNested("jump-boost.cooldown-minutes", 3);
                $cooldownSeconds = $cooldownMinutes * 60;
                
                // Check cooldown
                if(isset($this->jumpCooldowns[$playerName])){
                    $timeLeft = ($this->jumpCooldowns[$playerName] + $cooldownSeconds) - $currentTime;
                    if($timeLeft > 0){
                        $minutes = floor($timeLeft / 60);
                        $seconds = $timeLeft % 60;
                        $player->sendMessage("§cJump boost on cooldown! Wait " . $minutes . "m " . $seconds . "s.");
                        return;
                    }
                }
                
                // Apply jump boost
                $launchHeight = $this->plugin->getConfig()->getNested("jump-boost.launch-height", 8);
                
                // Launch player upward
                $player->setMotion($player->getMotion()->add(0, $launchHeight, 0));
                
                // Set cooldown
                $this->jumpCooldowns[$playerName] = $currentTime;
                
                $player->sendMessage("§aLaunched upward! Next use in " . $cooldownMinutes . " minutes.");
                return;
            } elseif($arena !== null && $arena->isPlayerAlive($player) && !$arena->isPVPMode()) {
                $player->sendMessage("§cJump boost only available during PVP mode!");
            }
        }
    }

    public function onInventoryTransaction(InventoryTransactionEvent $event){
        $player = $event->getTransaction()->getSource();
        $arena = $this->plugin->gameManager->getArenaByWorld($player->getLevel()->getName());
        
        if($arena !== null && $arena->isPlayerAlive($player)){
            foreach($event->getTransaction()->getActions() as $action){
                $item = $action->getSourceItem();
                
                // Protect feather, diamond sword, emerald (383:37), and armor from being dropped/moved
                if($item->getId() === Item::FEATHER || 
                   ($item->getId() === 383 && $item->getDamage() === 37) ||
                   $item->getId() === Item::DIAMOND_SWORD ||
                   $item->getId() === Item::DIAMOND_HELMET ||
                   $item->getId() === Item::DIAMOND_CHESTPLATE ||
                   $item->getId() === Item::DIAMOND_LEGGINGS ||
                   $item->getId() === Item::DIAMOND_BOOTS){
                    $event->setCancelled();
                    return;
                }
            }
        }
    }
    
    /**
     * Clean up cooldowns when player leaves server
     */
    public function onPlayerQuit(PlayerQuitEvent $event){
        $playerName = $event->getPlayer()->getName();
        unset($this->speedCooldowns[$playerName]);
        unset($this->jumpCooldowns[$playerName]);
    }
}