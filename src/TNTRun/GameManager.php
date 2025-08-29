<?php
namespace TNTRun;

class GameManager {

    private $plugin;
    public $arenas = [];

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
        $this->loadArenas();
    }

    public function loadArenas(){
        $this->arenas = [];
        $arenaNames = $this->plugin->getAllArenaNames();
        
        foreach($arenaNames as $name){
            $data = $this->plugin->loadArena($name);
            if($data !== null){
                $this->arenas[$name] = new Arena($this->plugin, $name, $data);
            } else {
                $this->plugin->getLogger()->warning("Failed to load arena: " . $name);
            }
        }
        
        $this->plugin->getLogger()->info("Loaded " . count($this->arenas) . " arenas from storage.");
    }

    public function getArena(string $name): ?Arena {
        return $this->arenas[$name] ?? null;
    }

    public function getArenaByWorld(string $world): ?Arena {
        foreach($this->arenas as $arena){
            if($arena->getWorldName() === $world){
                return $arena;
            }
        }
        return null;
    }
}
