<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion\session;

use pocketmine\network\mcpe\NetworkSession;

class SessionManager{

    /** @var Session[] */
    private static $sessions = [];

    public static function get(NetworkSession $session) : ?Session{
        var_dump($session->getIp() . ":" . $session->getPort());
        // var_dump($session->getDisplayName());
        var_dump(array_keys(self::$sessions)[0]);
        return self::$sessions[$session->getIp() . ":" . $session->getPort()] ?? null;
    }

    public static function remove(NetworkSession $session) {
        unset(self::$sessions[$session->getDisplayName()]);
    }

    public static function create(NetworkSession $session, int $protocol) {
        echo __METHOD__ . ", " . __LINE__ . ", created new session" . "\n";
        self::$sessions[$session->getIp() . ":" . $session->getPort()] = new Session($session, $protocol);
        // self::$sessions[$session->getDisplayName()] = new Session($session, $protocol);
    }

    public static function getProtocol(NetworkSession $session): ?int{
        if(($session = self::get($session)) !== null) {
            return $session->protocol;
        }
        return null;
    }
}