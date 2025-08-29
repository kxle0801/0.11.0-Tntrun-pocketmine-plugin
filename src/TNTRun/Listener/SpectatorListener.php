<?php
namespace TNTRun\Listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\item\Item;
use TNTRun\Main;

class SpectatorListener implements Listener {

    private $plugin;

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
    }

    public function onInteract(PlayerInteractEvent $event){
        $player = $event->getPlayer();
        $item = $player->getInventory()->getItemInHand();
        
        // Handle bed item for spectators only
        if($item->getId() === Item::BED){
            $arena = $this->plugin->gameManager->getArenaByWorld($player->getLevel()->getName());
            if($arena !== null && $arena->isPlayerSpectator($player)){
                $event->setCancelled();
                $player->sendMessage("§aReturning to hub...");
                $arena->removePlayer($player, true); // true = transfer to hub
            }
        }
    }

    public function onInventoryTransaction(InventoryTransactionEvent $event){
        $player = $event->getTransaction()->getSource();
        
        // Prevent spectators from moving items around
        $arena = $this->plugin->gameManager->getArenaByWorld($player->getLevel()->getName());
        if($arena !== null && $arena->isPlayerSpectator($player)){
            // Allow spectators to interact with bed item but prevent other changes
            foreach($event->getTransaction()->getActions() as $action){
                $item = $action->getSourceItem();
                if($item->getId() !== Item::BED){
                    $event->setCancelled();
                    return;
                }
            }
        }
    }
}