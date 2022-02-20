<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion;

use AkmalFairuz\MultiVersion\network\MultiVersionSessionAdapter;
use AkmalFairuz\MultiVersion\network\ProtocolConstants;
use AkmalFairuz\MultiVersion\network\Translator;
use AkmalFairuz\MultiVersion\session\SessionManager;
use AkmalFairuz\MultiVersion\task\CompressTask;
use AkmalFairuz\MultiVersion\task\DecompressTask;
use AkmalFairuz\MultiVersion\utils\Utils;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\handler\LoginPacketHandler;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PacketViolationWarningPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\StandardPacketBroadcaster;
use pocketmine\network\NetworkSessionManager;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\BinaryDataException;
use function in_array;
use function strlen;

class EventListener implements Listener{

    /** @var bool */
    public $cancel_send = false; // prevent recursive call
	private LoginPacket $packetHack;

	/**
	 * @priority LOWEST
     */
    public function onDataPacketReceive(DataPacketReceiveEvent $event) {
        $player = $event->getOrigin()->getPlayer();
        $packet = $event->getPacket();
        if($packet instanceof PacketViolationWarningPacket) {
            Loader::getInstance()->getLogger()->info("PacketViolationWarningPacket packet=" . PacketPool::getInstance()->getPacketById($packet->getPacketId())->getName() . ",message=" . $packet->getMessage() . ",type=" . $packet->getType() . ",severity=" . $packet->getSeverity());
        }
        if($packet instanceof LoginPacket) {
            if(!Loader::getInstance()->canJoin) {
//                $player->kick("Trying to join the server before CraftingManager registered", "");
                $event->cancel();
                return;
            }
			$this->packetHack = $packet;
			if(!in_array($packet->protocol, ProtocolConstants::SUPPORTED_PROTOCOLS, true) || Loader::getInstance()->isProtocolDisabled($packet->protocol)) {
//                $player->getNetworkSession()->sendDataPacket(PlayStatusPacket::create(PlayStatusPacket::LOGIN_FAILED_SERVER), true);
//                $player->kick($player->getServer()->getLanguage()->translateString("pocketmine.disconnect.incompatibleProtocol", [$packet->protocol]), "");
                $event->cancel();
                return;
            }
            if($packet->protocol === ProtocolInfo::CURRENT_PROTOCOL) {
                return;
            }

			$packet->protocol = ProtocolInfo::CURRENT_PROTOCOL;
        }
    }


	public function onPlayerJoin(PlayerJoinEvent $event){
		$player = $event->getPlayer();
		//Todo: put in onDataPacketReceive
		if($this->packetHack->protocol === ProtocolInfo::CURRENT_PROTOCOL){
			return;
		}
		Utils::forceSetProps($player, "networkSession", new MultiVersionSessionAdapter(Server::getInstance(), new NetworkSessionManager(), PacketPool::getInstance(), new MultiVersionPacketSender(), $player->getNetworkSession()->getBroadcaster(), $player->getNetworkSession()->getCompressor(), $player->getNetworkSession()->getIp(), $player->getNetworkSession()->getPort(), $this->packetHack->protocol));

		SessionManager::create($player, $this->packetHack->protocol);

		Translator::fromClient($this->packetHack, $this->packetHack->protocol, $player);
	}

    /**
     * @param PlayerQuitEvent $event
     * @priority HIGHEST
     */
    public function onPlayerQuit(PlayerQuitEvent $event) {
        SessionManager::remove($event->getPlayer());
    }

    /**
     * @param DataPacketSendEvent $event
     * @priority HIGHEST
     * @ignoreCancelled true
     */
    public function onDataPacketSend(DataPacketSendEvent $event) {
        if($this->cancel_send) {
            return;
        }
        // $player = $event->getPlayer();
        // $packet = $event->getPacket();

		$packets = $event->getPackets();
		$players = $event->getTargets();
		
		foreach ($packets as $packet){
			foreach ($players as $player){
				if($player->getPlayer() === null || !$player->isConnected())return;
				$protocol = SessionManager::getProtocol($player->getPlayer());
				if($protocol === null) {
					return;
				}
				
				if($packet instanceof ModalFormRequestPacket || $packet instanceof NetworkStackLatencyPacket) {
					return; // fix form and invmenu plugins not working
				}
				
				/*if($packet instanceof BatchPacket) {
					if($packet->isEncoded){
						if(Config::$ASYNC_BATCH_DECOMPRESSION && strlen($packet->buffer) >= Config::$ASYNC_BATCH_THRESHOLD) {
							try{
								$task = new DecompressTask($packet, function(BatchPacket $packet) use ($player, $protocol){
									$this->translateBatchPacketAndSend($packet, $player, $protocol);
								});
								Server::getInstance()->getAsyncPool()->submitTask($task);
							} catch(BinaryDataException $e) {}
							$event->cancel();
							return;
						}
						$packet->decode();
					}

					$this->translateBatchPacketAndSend($packet, $player, $protocol);
				} else {
					if($packet->isEncoded){
						$packet->decode();
					}
					$translated = true;
					$newPacket = Translator::fromServer($packet, $protocol, $player, $translated);
					if(!$translated) {
						return;
					}
					if($newPacket === null) {
						$event->cancel();
						return;
					}
					
					$batch = new BatchPacket();
					$batch->addPacket($newPacket);
					$batch->setCompressionLevel(7);
					$batch->encode();
					
					$this->cancel_send = true;
					$player->sendDataPacket($batch);
					$this->cancel_send = false;
				}*/
			}
		}
        $event->cancel();
    }

    private function translateBatchPacketAndSend(BatchPacket $packet, Player $player, int $protocol) {
        $newPacket = new BatchPacket();
        try{
            foreach($packet->getPackets() as $buf){
                $pk = PacketPool::getPacket($buf);
                if($pk instanceof CraftingDataPacket){
                    $this->cancel_send = true;
                    $player->getNetworkSession()->sendDataPacket(Loader::getInstance()->craftingManager->getCraftingDataPacketA($protocol));
                    $this->cancel_send = false;
                    continue;
                }
                $pk->decode();
                $translated = Translator::fromServer($pk, $protocol, $player);
                if($translated === null){
                    continue;
                }
                $newPacket->addPacket($translated);
            }
        } catch(\UnexpectedValueException $e) {}
        if(Config::$ASYNC_BATCH_COMPRESSION && strlen($newPacket->payload) >= Config::$ASYNC_BATCH_THRESHOLD){
            $task = new CompressTask($newPacket, function(BatchPacket $packet) use ($player) {
                $this->cancel_send = true;
                $player->getNetworkSession()->sendDataPacket($packet);
                $this->cancel_send = false;
            });
            Server::getInstance()->getAsyncPool()->submitTask($task);
            return;
        }
        $this->cancel_send = true;
        $newPacket->setCompressionLevel(7);
        $newPacket->encode();
        $player->getNetworkSession()->sendDataPacket($newPacket);
        $this->cancel_send = false;
    }
}
