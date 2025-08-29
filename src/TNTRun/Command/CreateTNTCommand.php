<?php
namespace TNTRun\Command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Player;
use TNTRun\Main;

class CreateTNTCommand extends Command {

    private $plugin;

    public function __construct(Main $plugin){
        parent::__construct("createtnt", "Create a TNT Run arena", "/createtnt <name>");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, $commandLabel, array $args){
        if(!$sender instanceof Player){
            $sender->sendMessage("§cThis command can only be used in-game.");
            return true;
        }

        if(count($args) < 1){
            $sender->sendMessage("§eUsage: /createtnt <arenaName>");
            return true;
        }

        $arenaName = strtolower($args[0]);
        $config = $this->plugin->getConfig();
        $config->set("arenas." . $arenaName, [
            "world" => $sender->getLevel()->getName(),
            "spawn" => [$sender->getX(), $sender->getY(), $sender->getZ()],
            "fall-blocks" => $this->plugin->fallBlocks
        ]);
        $config->save();

        $sender->sendMessage("§aArena §b" . $arenaName . " §acreated and saved to config.");
        return true;
    }
}