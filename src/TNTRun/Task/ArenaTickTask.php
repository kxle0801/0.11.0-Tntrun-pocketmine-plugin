<?php
namespace TNTRun\Task;

use pocketmine\scheduler\PluginTask;
use TNTRun\Arena;

class ArenaTickTask extends PluginTask {

    private $arena;

    public function __construct(Arena $arena){
        parent::__construct($arena->getPlugin());
        $this->arena = $arena;
    }

    public function onRun($tick){
        $this->arena->tick();
    }
}
