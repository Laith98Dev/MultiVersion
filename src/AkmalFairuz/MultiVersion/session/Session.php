<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion\session;

use pocketmine\network\mcpe\NetworkSession;
use pocketmine\player\Player;

class Session{

    /** @var int */
    public $protocol;
    /** @var Player */
    private $session;

    public function __construct(NetworkSession $session, int $protocol) {
        $this->session = $session;
        $this->protocol = $protocol;
    }
}