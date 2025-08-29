<?php
namespace TNTRun\Command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Player;
use TNTRun\Main;

class DeleteTNTCommand extends Command {

    private $plugin;

    public function __construct(Main $plugin){
        parent::__construct("deltnt", "Delete a TNT Run arena", "/deltnt <name>");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, $commandLabel, array $args){
        if(!$sender instanceof Player){
            $sender->sendMessage("§cThis command can only be used in-game.");
            return true;
        }

        if(count($args) < 1){
            $sender->sendMessage("§eUsage: /deltnt <arenaName>");
            return true;
        }

        $arenaName = strtolower($args[0]);
        $config = $this->plugin->getConfig();

        if(!$config->exists("arenas." . $arenaName)){
            $sender->sendMessage("§cArena §b" . $arenaName . " §cdoes not exist.");
            return true;
        }

        $config->remove("arenas." . $arenaName);
        $config->save();

        $sender->sendMessage("§aArena §b" . $arenaName . " §ahas been deleted.");
        return true;
    }
}