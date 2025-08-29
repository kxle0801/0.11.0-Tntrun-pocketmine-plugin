<?php
namespace TNTRun\Listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use TNTRun\Main;

class PlayerJoinListener implements Listener {

    private $plugin;

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
    }

    public function onJoin(PlayerJoinEvent $event){
        $player = $event->getPlayer();
        
        // Check if edit mode is enabled
        if($this->plugin->getConfig()->get("edit-mode", false)){
            // Edit mode - don't auto-join players
            if($player->hasPermission("tntrun.edit")){
                $player->sendMessage("§eEdit mode is active. Auto-join disabled.");
            }
            return;
        }

        // Check if auto-join is enabled
        if(!$this->plugin->getConfig()->get("auto-join-enabled", true)){
            return;
        }

        // Check if player has permission to be auto-joined
        if(!$player->hasPermission("tntrun.autojoin")){
            return;
        }

        // Get the auto-join arena
        $autoJoinArena = $this->plugin->getConfig()->get("auto-join-arena", "main");
        
        // Delay the auto-join slightly to ensure player is fully loaded
        $this->plugin->getServer()->getScheduler()->scheduleDelayedTask(new class($this->plugin, $player, $autoJoinArena) extends \pocketmine\scheduler\PluginTask {
            private $player;
            private $arenaName;

            public function __construct(Main $plugin, \pocketmine\Player $player, string $arenaName){
                parent::__construct($plugin);
                $this->player = $player;
                $this->arenaName = $arenaName;
            }

            public function onRun($currentTick){
                // Check if player is still online
                if($this->player->isOnline()){
                    /** @var Main $plugin */
                    $plugin = $this->getOwner();
                    
                    // Try to join the player to the arena
                    $success = $plugin->joinPlayerToArena($this->player, $this->arenaName, true);
                    
                    if(!$success){
                        // Auto-join failed, try fallback options
                        $this->tryFallbackArenas($plugin);
                    }
                }
            }

            private function tryFallbackArenas(Main $plugin){
                // Get all available arenas
                $arenas = $plugin->getConfig()->get("arenas", []);
                
                foreach($arenas as $name => $data){
                    // Skip the original arena we already tried
                    if($name === $this->arenaName) continue;
                    
                    // Skip incomplete arenas
                    if($data["region"] === null) continue;
                    
                    // Try to join this arena
                    if($plugin->joinPlayerToArena($this->player, $name, true)){
                        $this->player->sendMessage("§eJoined fallback arena §b" . $name . " §einstead.");
                        return;
                    }
                }
                
                // No arenas available
                $this->player->sendMessage("§cNo available TNT Run arenas at the moment.");
                $this->player->sendMessage("§eUse §b/listtnt §eto see arena status or §b/jointnt <name> §eto join manually.");
            }
        }, 20); // 1 second delay
    }
}