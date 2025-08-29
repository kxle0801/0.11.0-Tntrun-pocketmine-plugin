<?php
namespace TNTRun\Task;

use pocketmine\scheduler\PluginTask;
use TNTRun\Arena;

class WaitingCountdownTask extends PluginTask {

    private $arena;
    private $totalTicks = 0;
    private $waitTime;
    private $countdownTime;
    private $isInCountdown = false;

    public function __construct(Arena $arena){
        parent::__construct($arena->getPlugin());
        $this->arena = $arena;
        
        // Get configurable times (convert seconds to ticks)
        $this->waitTime = $arena->getPlugin()->getConfig()->get("wait-time-seconds", 60) * 20;
        $this->countdownTime = $arena->getPlugin()->getConfig()->get("countdown-seconds", 5) * 20;
    }

    public function onRun($tick){
        $this->totalTicks += 20; // We run every second (20 ticks)
        
        // Check if we still have enough players
        $alivePlayerCount = count($this->arena->getAlivePlayers());
        $minPlayers = $this->arena->getPlugin()->getConfig()->get("min-players-to-start", 2);
        $maxPlayers = $this->arena->getPlugin()->getConfig()->get("max-players-per-arena", 16);
        
        if($alivePlayerCount < $minPlayers){
            // Not enough players - cancel and reset
            $this->cancelTask();
            foreach($this->arena->getAllPlayers() as $player){
                if($player->isOnline()){
                    $player->sendMessage("§cNot enough players! Waiting cancelled.");
                }
            }
            return;
        }
        
        if(!$this->isInCountdown){
            // WAITING PHASE
            $waitRemaining = ($this->waitTime - $this->totalTicks) / 20;
            
            // Check if we have max players (skip waiting)
            if($alivePlayerCount >= $maxPlayers){
                $this->isInCountdown = true;
                $this->totalTicks = 0; // Reset for countdown phase
                
                foreach($this->arena->getAllPlayers() as $player){
                    if($player->isOnline()){
                        $player->sendMessage("§aArena is full! Starting countdown...");
                    }
                }
                return;
            }
            
            // Show waiting messages at intervals
            if($waitRemaining > 0){
                if($waitRemaining % 30 == 0 || $waitRemaining <= 10){
                    foreach($this->arena->getAllPlayers() as $player){
                        if($player->isOnline()){
                            $player->sendMessage("§eWaiting for more players... §7(" . $alivePlayerCount . "/" . $maxPlayers . ") §e- " . $waitRemaining . "s remaining");
                        }
                    }
                }
            } else {
                // Wait time over, start countdown
                $this->isInCountdown = true;
                $this->totalTicks = 0; // Reset for countdown phase
                
                foreach($this->arena->getAllPlayers() as $player){
                    if($player->isOnline()){
                        $player->sendMessage("§aStarting game with " . $alivePlayerCount . " players!");
                    }
                }
            }
        } else {
            // COUNTDOWN PHASE
            $countdownRemaining = ($this->countdownTime - $this->totalTicks) / 20;
            
            if($countdownRemaining > 0){
                // Show countdown in chat only
                foreach($this->arena->getAllPlayers() as $player){
                    if($player->isOnline()){
                        $player->sendMessage("§eGame starting in: §c" . $countdownRemaining . "§e seconds");
                    }
                }
            } else {
                // Countdown finished - start game
                $this->arena->setActive(true);
                
                foreach($this->arena->getAllPlayers() as $player){
                    if($player->isOnline()){
                        $player->sendMessage("§a=== TNT RUN STARTED! ===");
                    }
                }
                
                // Cancel this task
                $this->cancelTask();
            }
        }
    }
    
    private function cancelTask(){
        $handler = $this->getHandler();
        if($handler !== null){
            $handler->cancel();
        }
    }
}
