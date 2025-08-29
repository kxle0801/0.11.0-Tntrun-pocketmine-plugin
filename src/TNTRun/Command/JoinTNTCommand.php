<?php
namespace TNTRun\Command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Player;
use TNTRun\Main;

class JoinTNTCommand extends Command {

    private $plugin;

    public function __construct(Main $plugin){
        parent::__construct("jointnt", "Join a TNT Run arena", "/jointnt <name>");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, $commandLabel, array $args){
        if(!$sender instanceof Player){
            $sender->sendMessage("§cThis command can only be used in-game.");
            return true;
        }

        if(count($args) < 1){
            $sender->sendMessage("§eUsage: /jointnt <arenaName>");
            return true;
        }

        $arenaName = strtolower($args[0]);
        $config = $this->plugin->getConfig();

        if(!$config->exists("arenas." . $arenaName)){
            $sender->sendMessage("§cArena §b" . $arenaName . " §cdoes not exist.");
            return true;
        }

        $arenaData = $config->get("arenas." . $arenaName);
        $spawn = $arenaData["spawn"];
        $level = $this->plugin->getServer()->getLevelByName($arenaData["world"]);

        if($level === null){
            $sender->sendMessage("§cWorld §b" . $arenaData["world"] . " §cnot loaded.");
            return true;
        }

        $sender->teleport(new \pocketmine\math\Vector3($spawn[0], $spawn[1], $spawn[2]));
        $sender->sendMessage("§aJoined TNT Run arena §b" . $arenaName);
        return true;
    }
}