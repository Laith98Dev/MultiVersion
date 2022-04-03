<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion\compressor;

use pocketmine\network\mcpe\compression\ZlibCompressor;

class MultiVersionZlibCompressor extends ZlibCompressor {
    public static function new() : self{
        $ref = new \ReflectionClass(self::getInstance());
        $method = $ref->getMethod("make");
        $method->setAccessible(true);
        return $method->invoke(self::getInstance(), "");
    }
}