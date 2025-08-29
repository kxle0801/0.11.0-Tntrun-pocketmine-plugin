<?php
namespace TNTRun\Command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Player;
use TNTRun\Main;

class ListTNTCommand extends Command {

    private $plugin;

    public function __construct(Main $plugin){
        parent::__construct("listtnt", "List all TNT Run arenas", "/listtnt");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, $commandLabel, array $args){
        if(!$sender instanceof Player){
            $sender->sendMessage("§cThis command can only be used in-game.");
            return true;
        }

        $arenas = $this->plugin->getConfig()->get("arenas", []);

        if(empty($arenas)){
            $sender->sendMessage("§eNo TNT Run arenas have been created yet.");
            return true;
        }

        $sender->sendMessage("§aAvailable TNT Run arenas:");
        foreach(array_keys($arenas) as $name){
            $sender->sendMessage("§b- " . $name);
        }
        return true;
    }
}