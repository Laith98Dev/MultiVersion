<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion\session;

use pocketmine\network\mcpe\NetworkSession;

class SessionManager{

    /** @var Session[] */
    private static $sessions = [];

    public static function get(NetworkSession $session) : ?Session{
        return self::$sessions[$session->getDisplayName()] ?? null;
    }

    public static function remove(NetworkSession $session) {
        unset(self::$sessions[$session->getDisplayName()]);
    }

    public static function create(NetworkSession $session, int $protocol) {
        self::$sessions[$session->getDisplayName()] = new Session($session, $protocol);
    }

    public static function getProtocol(NetworkSession $session): ?int{
        if(($session = self::get($session)) !== null) {
            return $session->protocol;
        }
        return null;
    }
}