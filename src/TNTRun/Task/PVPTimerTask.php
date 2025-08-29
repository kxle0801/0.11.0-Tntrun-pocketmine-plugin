<?php
namespace TNTRun\Task;

use pocketmine\scheduler\PluginTask;
use TNTRun\Arena;

class PVPTimerTask extends PluginTask {

    private $arena;
    private $totalTicks = 0;
    private $pvpActivationTicks;
    private $warningTicks;
    private $warningGiven = false;

    public function __construct(Arena $arena){
        parent::__construct($arena->getPlugin());
        $this->arena = $arena;
        
        // Calculate timing from config (convert minutes/seconds to ticks)
        $pvpMinutes = $arena->getPlugin()->getConfig()->getNested("pvp-mode.activation-time-minutes", 3);
        $warningSeconds = $arena->getPlugin()->getConfig()->getNested("pvp-mode.warning-time-seconds", 30);
        
        $this->pvpActivationTicks = $pvpMinutes * 60 * 20; // minutes to ticks
        $this->warningTicks = $this->pvpActivationTicks - ($warningSeconds * 20); // warning time in ticks
    }

    public function onRun($tick){
        // Only run if arena is still active
        if(!$this->arena->isActive()){
            $this->getHandler()->cancel();
            return;
        }

        $this->totalTicks += 20; // We run every second (20 ticks)
        
        // Check for warning time
        if(!$this->warningGiven && $this->totalTicks >= $this->warningTicks){
            $this->sendPVPWarning();
            $this->warningGiven = true;
        }
        
        // Check for PVP activation time
        if($this->totalTicks >= $this->pvpActivationTicks){
            $this->activatePVPMode();
            // Cancel task by setting a flag or letting it be cancelled externally
            return;
        }
    }
    
    private function sendPVPWarning(){
        $warningSeconds = $this->arena->getPlugin()->getConfig()->getNested("pvp-mode.warning-time-seconds", 30);
        
        foreach($this->arena->getAlivePlayers() as $player){
            if($player->isOnline()){
                $player->sendMessage("§c§l⚠ WARNING ⚠");
                $player->sendMessage("§ePVP mode will activate in §c" . $warningSeconds . " seconds§e!");
                $player->sendMessage("§eGet ready for combat!");
            }
        }
        
        // Also notify spectators
        foreach($this->arena->getSpectatorPlayers() as $player){
            if($player->isOnline()){
                $player->sendMessage("§ePVP mode activating in §c" . $warningSeconds . " seconds§e!");
            }
        }
    }
    
    private function activatePVPMode(){
        $this->arena->activatePVPMode();
    }
}