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
use pocketmine\crafting\CraftingManager;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
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
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\StandardPacketBroadcaster;
use pocketmine\network\NetworkSessionManager;
use pocketmine\player\Player;
use pocketmine\player\PlayerInfo;
use pocketmine\Server;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\TextFormat;
use function in_array;
use function strlen;

class EventListener implements Listener
{

	/** @var bool */
	public $cancel_send = false; // prevent recursive call

	/**
	 * @priority LOWEST
	 */
	public function onDataPacketReceive(DataPacketReceiveEvent $event)
	{
		$origin = $event->getOrigin();
		$packet = $event->getPacket();
		if ($packet instanceof PacketViolationWarningPacket) {
			Loader::getInstance()->getLogger()->info("PacketViolationWarningPacket packet=" . PacketPool::getInstance()->getPacketById($packet->getPacketId())->getName() . ",message=" . $packet->getMessage() . ",type=" . $packet->getType() . ",severity=" . $packet->getSeverity());
		}
		if ($packet instanceof LoginPacket) {
			if (!Loader::getInstance()->canJoin) {
				$origin->disconnect("Trying to join the server before CraftingManager registered", false);
				$event->cancel();
				return;
			}
			if (!in_array($packet->protocol, ProtocolConstants::SUPPORTED_PROTOCOLS, true) || Loader::getInstance()->isProtocolDisabled($packet->protocol)) {
				$origin->sendDataPacket(PlayStatusPacket::create(PlayStatusPacket::LOGIN_FAILED_SERVER), true);
				$origin->disconnect(Server::getInstance()->getLanguage()->translateString("pocketmine.disconnect.incompatibleProtocol", [$packet->protocol]), false);
				$event->cancel();
				return;
			}
			if ($packet->protocol === ProtocolInfo::CURRENT_PROTOCOL) {
				return;
			}

			$packet->protocol = ProtocolInfo::CURRENT_PROTOCOL;

			Utils::forceSetProps($origin, "this", new MultiVersionSessionAdapter(Server::getInstance(), new NetworkSessionManager(), PacketPool::getInstance(), new MultiVersionPacketSender(), $origin->getBroadcaster(), $origin->getCompressor(), $origin->getIp(), $origin->getPort(), $packet->protocol));

			SessionManager::create($origin, $packet->protocol);

			Translator::fromClient($packet, $packet->protocol, $origin);
		}
	}

	/**
	 * @param PlayerQuitEvent $event
	 * @priority HIGHEST
	 */
	public function onPlayerQuit(PlayerQuitEvent $event)
	{
		SessionManager::remove($event->getPlayer()->getNetworkSession());
	}

	/**
	 * @param DataPacketSendEvent $event
	 * @priority HIGHEST
	 * @ignoreCancelled true
	 */
	public function onDataPacketSend(DataPacketSendEvent $event)
	{
		if ($this->cancel_send) {
			return;
		}

		$packets = $event->getPackets();
		$players = $event->getTargets();

		foreach ($packets as $packet) {
			foreach ($players as $session) {
				$protocol = SessionManager::getProtocol($session);
				$in = PacketSerializer::decoder($packet->getName(), 0, new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary()));
				if ($protocol === null) {
					return;
				}

				if ($packet instanceof ModalFormRequestPacket || $packet instanceof NetworkStackLatencyPacket) {
					return; // fix form and invmenu plugins not working
				}

				if ($packet instanceof CraftingDataPacket) {
					$this->cancel_send = true;
					$session->sendDataPacket(Loader::getInstance()->craftingManager->getCache(new CraftingManager(), $protocol));
					$this->cancel_send = false;
					continue;
				}
				$packet->decode($in);
				$translated = Translator::fromServer($packet, $protocol, $session);
				if ($translated === null) {
					continue;
				}
				PacketPool::getInstance()->registerPacket($translated);

				$packet->decode($in);
				$translated = true;
				$newPacket = Translator::fromServer($packet, $protocol, $session, $translated);
				if(!$translated) {
					return;
				}
				if($newPacket === null) {
					$event->cancel();
					return;
				}

				/*$batch = new BatchPacket();
				$batch->addPacket($newPacket);
				$batch->setCompressionLevel(7);
				$batch->encode();

				$task = new CompressTask($newPacket, function (BatchPacket $packet) use ($player) {
					$this->cancel_send = true;
					$player->getNetworkSession()->sendDataPacket($packet);
					$this->cancel_send = false;
				});
				Server::getInstance()->getAsyncPool()->submitTask($task);

				$this->cancel_send = true;
				$session->sendDataPacket($batch);*/
				$decompress = new DecompressTask($packet, function () use ($session, $packet) {
					$this->cancel_send = true;
					$session->sendDataPacket($packet);
					$this->cancel_send = false;
				});
				Server::getInstance()->getAsyncPool()->submitTask($decompress);
				$compress = new CompressTask($packet, function () use ($session, $packet) {
					$this->cancel_send = true;
					$session->sendDataPacket($packet);
					$this->cancel_send = false;
				});
				Server::getInstance()->getAsyncPool()->submitTask($compress);
				if($this->cancel_send === true){
					$this->cancel_send = false;
				}
			}
		}
		$event->cancel();
	}
}
