<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion\network;

use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\PacketSender;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\NetworkSessionManager;
use pocketmine\network\PacketHandlingException;
use pocketmine\Server;
use pocketmine\timings\Timings;
use function base64_encode;
use function bin2hex;
use function strlen;
use function substr;

class MultiVersionSessionAdapter extends NetworkSession {

    /** @var int */
    protected $protocol;

    public function __construct(Server $server, NetworkSessionManager $manager, PacketPool $packetPool, PacketSender $sender, PacketBroadcaster $broadcaster, Compressor $compressor, string $ip, int $port, int $protocol){
        parent::__construct($server, $manager, $packetPool, $sender, $broadcaster, $compressor, $ip, $port);
        $this->protocol = $protocol;
    }

	public function handleDataPacket(Packet $packet, string $buffer): void
	{
		if(!($packet instanceof ServerboundPacket)){
			throw new PacketHandlingException("Unexpected non-serverbound packet");
		}

		$timings = Timings::getReceiveDataPacketTimings($packet);
		$timings->startTiming();

		try{
			$stream = PacketSerializer::decoder($buffer, 0, $this->getPacketSerializerContext());
			try{
				$packet->decode($stream);
			}catch(PacketDecodeException $e){
				throw PacketHandlingException::wrap($e);
			}
			if(!$stream->feof()){
				$remains = substr($stream->getBuffer(), $stream->getOffset());
				$this->getLogger()->debug("Still " . strlen($remains) . " bytes unread in " . $packet->getName() . ": " . bin2hex($remains));
			}

			$ev = new DataPacketReceiveEvent($this, $packet);
			$ev->call();
			if(!$ev->isCancelled() && ($this->getHandler() === null || !$packet->handle($this->getHandler()))){
				$this->getLogger()->debug("Unhandled " . $packet->getName() . ": " . base64_encode($stream->getBuffer()));
			}
		}finally{
			$timings->stopTiming();
		}
	}
}