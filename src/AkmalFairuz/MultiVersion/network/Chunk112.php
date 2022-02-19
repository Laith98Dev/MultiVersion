<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion\network;

use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\block\tile\Spawnable;
use function chr;

class Chunk112{

    public static function serialize(World $world, LevelChunkPacket $origin): ?LevelChunkPacket{
        $x = $origin->getChunkX();
        $z = $origin->getChunkZ();
        $chunk = $world->getChunk($x, $z);
        if($chunk !== null){
            $payload = self::networkSerialize($chunk);
            return LevelChunkPacket::create($x, $z, $origin->getSubChunkCount() - 4, false, $origin->getUsedBlobHashes(),  $payload);
        }
        return null;
    }

    public static function networkSerialize(Chunk $chunk) {
        $result = "";
        $subChunkCount = $chunk->getSubChunkSendCount();
        for($y = 0; $y < $subChunkCount; ++$y){
            $result .= $chunk->getSubChunk($y)->networkSerialize();
        }
        $result .= $chunk->getBiomeIdArray() . chr(0); //border block array count
        //Border block entry format: 1 byte (4 bits X, 4 bits Z). These are however useless since they crash the regular client.

        foreach($chunk->getTiles() as $tile){
            if($tile instanceof Spawnable){
                $result .= $tile->getSerializedSpawnCompound();
            }
        }

        return $result;
    }
}
