<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion;

use AkmalFairuz\MultiVersion\compressor\MultiVersionZlibCompressor;
use AkmalFairuz\MultiVersion\network\MultiVersionSessionAdapter;
use AkmalFairuz\MultiVersion\network\ProtocolConstants;
use AkmalFairuz\MultiVersion\network\Translator;
use AkmalFairuz\MultiVersion\session\SessionManager;
use AkmalFairuz\MultiVersion\task\CompressTask;
use AkmalFairuz\MultiVersion\task\DecompressTask;
use AkmalFairuz\MultiVersion\utils\Utils;
use pocketmine\crafting\CraftingManager;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PacketViolationWarningPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\NetworkSessionManager;
use pocketmine\Server;
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
				echo __METHOD__ . ", " . __LINE__ . ", CraftingManager not registered" . "\n";
				$origin->disconnect("Trying to join the server before CraftingManager registered", false);
				$event->cancel();
				return;
			}
			if (!in_array($packet->protocol, ProtocolConstants::SUPPORTED_PROTOCOLS, true) || Loader::getInstance()->isProtocolDisabled($packet->protocol)) {
				echo __METHOD__ . ", " . __LINE__ . ", Disabled Protocol" . "\n";
				$origin->sendDataPacket(PlayStatusPacket::create(PlayStatusPacket::LOGIN_FAILED_SERVER), true);
				$origin->disconnect(Server::getInstance()->getLanguage()->translateString("pocketmine.disconnect.incompatibleProtocol", [$packet->protocol]), false);
				$event->cancel();
				return;
			}
			if ($packet->protocol === ProtocolInfo::CURRENT_PROTOCOL) {
				echo __METHOD__ . ", " . __LINE__ . ", Currently Protocol" . "\n";
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
					echo __METHOD__ . ", " . __LINE__ . ", null Protocol" . "\n";
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
				// $packet->decode($in);
				$translated = Translator::fromServer($packet, $protocol, $session);
				if ($translated === null) {
					echo __METHOD__ . ", " . __LINE__ . ", translate faild 1" . "\n";
					continue;
				}
				PacketPool::getInstance()->registerPacket($translated);

				// $packet->decode($in);
				$translated = true;
				$newPacket = Translator::fromServer($packet, $protocol, $session, $translated);
				if(!$translated) {
					echo __METHOD__ . ", " . __LINE__ . ", translate faild 2" . "\n";
					return;
				}
				if($newPacket === null) {
					$event->cancel();
					return;
				}

				// $decompress = new DecompressTask($packet, function () use ($session, $packet) {
				// 	$session->sendDataPacket($packet);
				// });
				// Server::getInstance()->getAsyncPool()->submitTask($decompress);
				$this->cancel_send = true;
				//$compressor = MultiVersionZlibCompressor::make();
				$context = new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary());
				$batch = Server::getInstance()->prepareBatch(PacketBatch::fromPackets($context, [$packet]), MultiVersionZlibCompressor::getInstance());
				$session->queueCompressed($batch);
				$this->cancel_send = false;
				// $compress = new CompressTask($packet, function () use ($session, $packet) {
				// 	$this->cancel_send = true;
				// 	$session->sendDataPacket($packet);
				// 	$this->cancel_send = false;
				// });
				// Server::getInstance()->getAsyncPool()->submitTask($compress);
				if($this->cancel_send === true){
					$this->cancel_send = false;
				}
			}
		}
		$event->cancel();
	}
}
