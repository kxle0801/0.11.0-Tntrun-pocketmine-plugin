<?php
namespace TNTRun\Listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use TNTRun\Main;

class MovementListener implements Listener {

    private $plugin;

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
    }

    public function onPlayerMove(PlayerMoveEvent $event){
        $player = $event->getPlayer();
        $arena = $this->plugin->gameManager->getArenaByWorld($player->getLevel()->getName());
        
        if($arena !== null && $arena->isPlayerAlive($player)){
            // Prevent movement if game is not active (waiting/countdown phase)
            if(!$arena->isActive()){
                $event->setCancelled();
                // Optional: Send message occasionally to explain why they can't move
                static $messageCounter = [];
                $playerName = $player->getName();
                
                if(!isset($messageCounter[$playerName])){
                    $messageCounter[$playerName] = 0;
                }
                
                $messageCounter[$playerName]++;
                
                // Send message every 60 movement attempts (reduce spam)
                if($messageCounter[$playerName] % 60 == 1){
                    $player->sendMessage("§eWait for the game to start before moving!");
                }
            } else {
                // Game is active, clear any message counters
                static $messageCounter = [];
            }
        }
    }
}