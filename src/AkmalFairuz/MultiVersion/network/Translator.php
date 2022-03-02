<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion\network;

use AkmalFairuz\MultiVersion\network\convert\MultiVersionGlobalItemTypeDictionary;
use AkmalFairuz\MultiVersion\network\convert\MultiVersionItemTypeDictionary;
use AkmalFairuz\MultiVersion\network\convert\MultiVersionRuntimeBlockMapping;
use AkmalFairuz\MultiVersion\network\translator\AddItemActorPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\AddPlayerPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\AnimateEntityPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\AvailableCommandsPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\CraftingDataPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\CreativeContentPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\GameRulesChangedPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\InventoryContentPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\InventorySlotPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\InventoryTransactionPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\MobArmorEquipmentPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\MobEquipmentPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\NpcRequestPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\PlayerListPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\PlayerSkinPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\ResourcePacksInfoPacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\SetTitlePacketTranslator;
use AkmalFairuz\MultiVersion\network\translator\StartGamePacketTranslator;
use pocketmine\block\BlockFactory;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\JwtException;
use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\AddItemActorPacket;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\AnimateEntityPacket;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\CreativeContentPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\GameRulesChangedPacket;
use pocketmine\network\mcpe\protocol\InventoryContentPacket;
use pocketmine\network\mcpe\protocol\InventorySlotPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\MobArmorEquipmentPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\NpcRequestPacket;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\PlayerSkinPacket;
use pocketmine\network\mcpe\protocol\ResourcePacksInfoPacket;
use pocketmine\network\mcpe\protocol\ResourcePackStackPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\protocol\SetTitlePacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataTypes;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\network\mcpe\protocol\types\login\ClientData;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\Player;
use pocketmine\utils\BinaryStream;

class Translator{

	/**
	 * @throws PacketHandlingException
	 */
	public static function parseClientData(string $clientDataJwt) : ClientData{
		try{
			[, $clientDataClaims, ] = JwtUtils::parse($clientDataJwt);
		}catch(JwtException $e){
			throw PacketHandlingException::wrap($e);
		}

		$mapper = new \JsonMapper;
		$mapper->bEnforceMapType = false; //TODO: we don't really need this as an array, but right now we don't have enough models
		$mapper->bExceptionOnMissingData = true;
		$mapper->bExceptionOnUndefinedProperty = true;
		try{
			$clientData = $mapper->map($clientDataClaims, new ClientData);
		}catch(\JsonMapper_Exception $e){
			throw PacketHandlingException::wrap($e);
		}
		return $clientData;
	}

    public static function fromClient(DataPacket $packet, int $protocol, NetworkSession $session = null) : DataPacket{
        $pid = $packet::NETWORK_ID;
        switch($pid) {
            case LoginPacket::NETWORK_ID:
                /** @var LoginPacket $packet */
		//todo use JwtUtils instead
		$clientData = self::parseClientData($packet->clientDataJwt);
                if($protocol < ProtocolConstants::BEDROCK_1_17_30) {
			$clientData->SkinGeometryDataEngineVersion = "";
                }
                return $packet;
            case PlayerSkinPacket::NETWORK_ID:
                /** @var PlayerSkinPacket $packet */
                if($protocol < ProtocolConstants::BEDROCK_1_17_30) {
                    self::decodeHeader($packet);
                    PlayerSkinPacketTranslator::deserialize($packet, $protocol);
                }
                return $packet;
            case InventoryTransactionPacket::NETWORK_ID:
                /** @var InventoryTransactionPacket $packet */
                self::decodeHeader($packet);
                InventoryTransactionPacketTranslator::deserialize($packet, $protocol);
                return $packet;
            case LevelSoundEventPacket::NETWORK_ID:
                /** @var LevelSoundEventPacket $packet */
		$stream = PacketSerializer::decoder(file_get_contents(\pocketmine\BEDROCK_DATA_PATH . "level_sound_id_map.json"), 0, new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary()));
		$packet->decode($stream);
                switch($packet->sound) {
                    case LevelSoundEvent::PLACE:
                    case LevelSoundEvent::BREAK_BLOCK:
                        $block = MultiVersionRuntimeBlockMapping::fromStaticRuntimeId($packet->extraData, $protocol);
                        $packet->extraData = RuntimeBlockMapping::getInstance()->toRuntimeId(BlockFactory::getInstance()->get($block[0], $block[1])->getFullId());
                        return $packet;
                }
                return $packet;
            case NpcRequestPacket::NETWORK_ID:
                /** @var NpcRequestPacket $packet */
                self::decodeHeader($packet);
                NpcRequestPacketTranslator::deserialize($packet, $protocol);
                return $packet;
            case MobEquipmentPacket::NETWORK_ID:
                /** @var MobEquipmentPacket $packet */
                self::decodeHeader($packet);
                MobEquipmentPacketTranslator::deserialize($packet, $protocol);
                return $packet;
            case MobArmorEquipmentPacket::NETWORK_ID:
                /** @var MobArmorEquipmentPacket $packet */
                self::decodeHeader($packet);
                MobArmorEquipmentPacketTranslator::deserialize($packet, $protocol);
                return $packet;
        }
        return $packet;
    }

    public static function fromServer(Packet $packet, int $protocol, NetworkSession $session = null, bool &$translated = true) : ?DataPacket {
        switch($packet->pid()) {
            case ResourcePackStackPacket::NETWORK_ID:
                /** @var ResourcePackStackPacket $packet */
                $packet->baseGameVersion = "1.16.220";
                return $packet;
            case UpdateBlockPacket::NETWORK_ID:
                /** @var UpdateBlockPacket $packet */
                $block = RuntimeBlockMapping::getInstance()->fromNetworkId($packet->blockRuntimeId);
                $packet->blockRuntimeId = MultiVersionRuntimeBlockMapping::toStaticRuntimeId($block[0], $block[1], $protocol);
                return $packet;
            case LevelSoundEventPacket::NETWORK_ID:
                /** @var LevelSoundEventPacket $packet */
                switch($packet->sound) {
                    case LevelSoundEvent::PLACE:
                    case LevelSoundEvent::BREAK_BLOCK:
                        $block = RuntimeBlockMapping::getInstance()->fromNetworkId($packet->extraData);
                        $packet->extraData = MultiVersionRuntimeBlockMapping::toStaticRuntimeId($block[0], $block[1], $protocol);
                        return $packet;
                }
                return $packet;
            case AddActorPacket::NETWORK_ID:
                /** @var AddActorPacket $packet */
                switch($packet->type) {
                    case "minecraft:falling_block":
                        if(isset($packet->metadata[EntityMetadataProperties::VARIANT])){
                            $block = RuntimeBlockMapping::getInstance()->fromNetworkId($packet->metadata[EntityMetadataProperties::VARIANT][1]);
                            $packet->metadata[EntityMetadataProperties::VARIANT] = [EntityMetadataTypes::INT, MultiVersionRuntimeBlockMapping::toStaticRuntimeId($block[0], $block[1], $protocol)];
                        }
                        return $packet;
                }
                return $packet;
            case LevelEventPacket::NETWORK_ID:
                /** @var LevelEventPacket $packet */
                switch($packet->evid) {
                    case LevelEvent::PARTICLE_DESTROY:
                        $block = RuntimeBlockMapping::getInstance()->fromNetworkId($packet->data);
                        $packet->data = MultiVersionRuntimeBlockMapping::toStaticRuntimeId($block[0], $block[1], $protocol);
                        return $packet;
                    case LevelEvent::PARTICLE_PUNCH_BLOCK:
                        $position = $packet->position;
                        $block = $session->getPlayer()->getWorld()->getBlock($position);
                        if($block->getId() === 0) {
                            return null;
                        }
                        $face = $packet->data & ~RuntimeBlockMapping::getInstance()->get($block->getId(), $block->getMeta())->getFullId();
                        $packet->data = MultiVersionRuntimeBlockMapping::toStaticRuntimeId($block->getId(), $block->getMeta(), $protocol) | $face;
                        return $packet;
                }
                return $packet;
            case LevelChunkPacket::NETWORK_ID:
                /** @var LevelChunkPacket $packet */
                if($protocol <= ProtocolConstants::BEDROCK_1_17_40) {
                    if($session->getPlayer()->getWorld() !== null){
                        return Chunk112::serialize($session->getPlayer()->getWorld(), $packet);
                    }
                    return null;
                }
                return $packet;
            case AnimateEntityPacket::NETWORK_ID:
                /** @var AnimateEntityPacket $packet */
                self::encodeHeader($packet);
                AnimateEntityPacketTranslator::serialize($packet, $protocol);
                return $packet;
            case CraftingDataPacket::NETWORK_ID:
                /** @var CraftingDataPacket $packet */
                self::encodeHeader($packet);
                CraftingDataPacketTranslator::serialize($packet, $protocol);
                return $packet;
            case PlayerListPacket::NETWORK_ID:
                /** @var PlayerListPacket $packet */
                self::encodeHeader($packet);
                PlayerListPacketTranslator::serialize($packet, $protocol);
                return $packet;
            case StartGamePacket::NETWORK_ID:
                /** @var StartGamePacket $packet */
                $packet->itemTable = MultiVersionGlobalItemTypeDictionary::getInstance()->getDictionary($protocol)->getEntries($protocol);
                self::encodeHeader($packet);
                StartGamePacketTranslator::serialize($packet, $protocol);
                return $packet;
            case PlayerSkinPacket::NETWORK_ID:
                /** @var PlayerSkinPacket $packet */
                self::encodeHeader($packet);
                PlayerSkinPacketTranslator::serialize($packet, $protocol);
                return $packet;
            case AddItemActorPacket::NETWORK_ID:
                /** @var AddItemActorPacket $packet */
                self::encodeHeader($packet);
                AddItemActorPacketTranslator::serialize($packet, $protocol);
                return $packet;
            case InventoryContentPacket::NETWORK_ID:
                /** @var InventoryContentPacket $packet */
                self::encodeHeader($packet);
                InventoryContentPacketTranslator::serialize($packet, $protocol);
                return $packet;
            case MobEquipmentPacket::NETWORK_ID:
                /** @var MobEquipmentPacket $packet */
                self::encodeHeader($packet);
                MobEquipmentPacketTranslator::serialize($packet, $protocol);
                return $packet;
            case MobArmorEquipmentPacket::NETWORK_ID:
                /** @var MobArmorEquipmentPacket $packet */
                self::encodeHeader($packet);
                MobArmorEquipmentPacketTranslator::serialize($packet, $protocol);
                return $packet;
            case AddPlayerPacket::NETWORK_ID:
                /** @var AddPlayerPacket $packet */
                self::encodeHeader($packet);
                AddPlayerPacketTranslator::serialize($packet, $protocol);
                return $packet;
            case InventorySlotPacket::NETWORK_ID:
                /** @var InventorySlotPacket $packet */
                self::encodeHeader($packet);
                InventorySlotPacketTranslator::serialize($packet, $protocol);
                return $packet;
            case InventoryTransactionPacket::NETWORK_ID:
                /** @var InventoryTransactionPacket $packet */
                self::encodeHeader($packet);
                InventoryTransactionPacketTranslator::serialize($packet, $protocol);
                return $packet;
            case CreativeContentPacket::NETWORK_ID:
                /** @var CreativeContentPacket $packet */
                self::encodeHeader($packet);
                CreativeContentPacketTranslator::serialize($packet, $protocol);
                return $packet;
            case AvailableCommandsPacket::NETWORK_ID:
                /** @var AvailableCommandsPacket $packet */
                self::encodeHeader($packet);
                AvailableCommandsPacketTranslator::serialize($packet, $protocol);
                return $packet;
            case SetTitlePacket::NETWORK_ID:
                /** @var SetTitlePacket $packet */
                self::encodeHeader($packet);
                SetTitlePacketTranslator::serialize($packet, $protocol);
                return $packet;
            case ResourcePacksInfoPacket::NETWORK_ID:
                /** @var ResourcePacksInfoPacket $packet */
                self::encodeHeader($packet);
                ResourcePacksInfoPacketTranslator::serialize($packet, $protocol);
                return $packet;
            case GameRulesChangedPacket::NETWORK_ID:
                /** @var GameRulesChangedPacket $packet */
                self::encodeHeader($packet);
                GameRulesChangedPacketTranslator::serialize($packet, $protocol);
                return $packet;
        }
        $translated = false;
        return $packet;
    }

    public static function encodeHeader(DataPacket $packet) {
		$in = new BinaryStream();
		$in->rewind();
		$in->putUnsignedVarInt(
            $packet::NETWORK_ID |
            ($packet->senderSubId << 10) |
            ($packet->recipientSubId << 12)
        );
    }

    public static function decodeHeader(DataPacket $packet) {
		$out = new BinaryStream();
        $packet->offset = 0;
        $header = $out->getUnsignedVarInt();
        $pid = $header & $packet::PID_MASK;
        if($pid !== $packet::NETWORK_ID){
            throw new \UnexpectedValueException("Expected " . $packet::NETWORK_ID . " for packet ID, got $pid");
        }
        $packet->senderSubId = ($header >> 10) & 0x03;
        $packet->recipientSubId = ($header >> 12) & 0x03;
    }
}
