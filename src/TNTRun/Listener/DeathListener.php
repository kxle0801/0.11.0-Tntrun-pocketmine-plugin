<?php
namespace TNTRun\Listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\Player;
use TNTRun\Main;

class DeathListener implements Listener {

    private $plugin;

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
    }

    public function onDeath(PlayerDeathEvent $event){
        $player = $event->getEntity();
        $arena = $this->plugin->gameManager->getArenaByWorld($player->getLevel()->getName());

        if($arena !== null && $arena->isPlayerAlive($player)){
            $event->setDrops([]); // Don't drop items in TNT Run
            
            if($this->plugin->getConfig()->getNested("spectator.auto-spectate-on-death", true)){
                // Move to spectator mode instead of eliminating
                $arena->movePlayerToSpectator($player, "death");
            } else {
                // Old behavior - remove player completely
                $arena->removePlayer($player);
            }
        }
    }

    public function onDamage(EntityDamageEvent $event){
        $entity = $event->getEntity();
        
        if($entity instanceof Player){
            $arena = $this->plugin->gameManager->getArenaByWorld($entity->getLevel()->getName());
            
            if($arena !== null){
                // Check if player is alive in the arena
                if($arena->isPlayerAlive($entity)){
                    
                    // Handle PVP damage prevention
                    if($event instanceof EntityDamageByEntityEvent){
                        $damager = $event->getDamager();
                        
                        // If damage is from another player and PVP mode is not active, cancel it
                        if($damager instanceof Player && !$arena->isPVPMode()){
                            $event->setCancelled();
                            $damager->sendMessage("§cPVP is not active yet! Wait for PVP mode.");
                            return;
                        }
                    }
                    
                    // Handle fall damage for alive players
                    if($event->getCause() === EntityDamageEvent::CAUSE_FALL){
                        // Cancel all fall damage within TNT Run arenas
                        $event->setCancelled();
                        
                        // Check elimination threshold using config values
                        $baseYLevel = $this->plugin->getConfig()->getNested("elimination.base-y-level", 73);
                        $maxFallDistance = $this->plugin->getConfig()->getNested("elimination.max-fall-distance", 5);
                        $eliminationY = $baseYLevel - $maxFallDistance;
                        
                        if($entity->getY() < $eliminationY){
                            // Player fell below elimination threshold
                            if($this->plugin->getConfig()->getNested("spectator.auto-spectate-on-death", true)){
                                // Move to spectator mode
                                $arena->movePlayerToSpectator($entity, "fell");
                            } else {
                                // Old behavior - remove player
                                $entity->sendMessage("§cYou fell too far!");
                                $arena->removePlayer($entity);
                            }
                        }
                    }
                } elseif($arena->isPlayerSpectator($entity)) {
                    // Spectators should not take damage
                    $event->setCancelled();
                }
            }
        }
    }
}