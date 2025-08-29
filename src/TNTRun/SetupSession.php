<?php
namespace TNTRun;

use pocketmine\Player;

class SetupSession {

    public $arenaName;
    public $pos1 = null;
    public $pos2 = null;

    public function __construct(string $arenaName){
        $this->arenaName = $arenaName;
    }

    public function isComplete(): bool {
        return $this->pos1 !== null && $this->pos2 !== null;
    }
}
