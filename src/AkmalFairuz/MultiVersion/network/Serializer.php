<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion\network;

use AkmalFairuz\MultiVersion\network\convert\MultiVersionItemTranslator;
use AkmalFairuz\MultiVersion\network\convert\MultiVersionGlobalItemTypeDictionary;
use AkmalFairuz\MultiVersion\network\convert\MultiVersionRuntimeBlockMapping;
use pocketmine\block\BlockLegacyIds;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\network\mcpe\protocol\types\GameRuleType;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\skin\PersonaPieceTintColor;
use pocketmine\network\mcpe\protocol\types\skin\PersonaSkinPiece;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinAnimation;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use pocketmine\utils\BinaryStream;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use function count;

class Serializer{

    public static function putSkin(SkinData $skin, DataPacket $packet, int $protocol){
		$in = new BinaryStream();
        $in->putString($skin->getSkinId());
        $in->putString($skin->getPlayFabId());
        $in->putString($skin->getResourcePatch());
        self::putSkinImage($skin->getSkinImage(), $packet);
        $in->putLInt(count($skin->getAnimations()));
        foreach($skin->getAnimations() as $animation){
            self::putSkinImage($animation->getImage(), $packet);
            $in->putLInt($animation->getType());
            $in->putLFloat($animation->getFrames());
            $in->putLInt($animation->getExpressionType());
        }
        self::putSkinImage($skin->getCapeImage(), $packet);
        $in->putString($skin->getGeometryData());
        if($protocol >= ProtocolConstants::BEDROCK_1_17_30){
            $in->putString($skin->getGeometryDataEngineVersion());
        }
        $in->putString($skin->getAnimationData());
        if($protocol < ProtocolConstants::BEDROCK_1_17_30) {
            $in->putBool($skin->isPremium());
            $in->putBool($skin->isPersona());
            $in->putBool($skin->isPersonaCapeOnClassic());
        }
        $in->putString($skin->getCapeId());
        $in->putString($skin->getFullSkinId());
        $in->putString($skin->getArmSize());
        $in->putString($skin->getSkinColor());
        $in->putLInt(count($skin->getPersonaPieces()));
        foreach($skin->getPersonaPieces() as $piece){
            $in->putString($piece->getPieceId());
            $in->putString($piece->getPieceType());
            $in->putString($piece->getPackId());
            $in->putBool($piece->isDefaultPiece());
            $in->putString($piece->getProductId());
        }
        $in->putLInt(count($skin->getPieceTintColors()));
        foreach($skin->getPieceTintColors() as $tint){
            $in->putString($tint->getPieceType());
            $in->putLInt(count($tint->getColors()));
            foreach($tint->getColors() as $color){
                $in->putString($color);
            }
        }
        if($protocol >= ProtocolConstants::BEDROCK_1_17_30){
            $in->putBool($skin->isPremium());
            $in->putBool($skin->isPersona());
            $in->putBool($skin->isPersonaCapeOnClassic());
            $in->putBool($skin->isPrimaryUser());
        }
    }

    public static function getSkin(DataPacket $packet, int $protocol) : SkinData{
		$out = new BinaryStream();
        $skinId = $out->getString();
        $skinPlayFabId = $out->getString();
        $skinResourcePatch = $out->getString();
        $skinData = self::getSkinImage($out);
        $animationCount = $out->getLInt();
        $animations = [];
        for($i = 0; $i < $animationCount; ++$i){
            $skinImage = self::getSkinImage($out);
            $animationType = $out->getLInt();
            $animationFrames = $out->getLFloat();
            $expressionType = $out->getLInt();
            $animations[] = new SkinAnimation($skinImage, $animationType, $animationFrames, $expressionType);
        }
        $capeData = self::getSkinImage($out);
        $geometryData = $out->getString();
        if($protocol >= ProtocolConstants::BEDROCK_1_17_30){
            $geometryDataVersion = $out->getString();
        }
        $animationData = $out->getString();
        if($protocol < ProtocolConstants::BEDROCK_1_17_30) {
            $premium = $out->getBool();
            $persona = $out->getBool();
            $capeOnClassic = $out->getBool();
        }
        $capeId = $out->getString();
        $fullSkinId = $out->getString();
        $armSize = $out->getString();
        $skinColor = $out->getString();
        $personaPieceCount = $out->getLInt();
        $personaPieces = [];
        for($i = 0; $i < $personaPieceCount; ++$i){
            $pieceId = $out->getString();
            $pieceType = $out->getString();
            $packId = $out->getString();
            $isDefaultPiece = $out->getBool();
            $productId = $out->getString();
            $personaPieces[] = new PersonaSkinPiece($pieceId, $pieceType, $packId, $isDefaultPiece, $productId);
        }
        $pieceTintColorCount = $out->getLInt();
        $pieceTintColors = [];
        for($i = 0; $i < $pieceTintColorCount; ++$i){
            $pieceType = $out->getString();
            $colorCount = $out->getLInt();
            $colors = [];
            for($j = 0; $j < $colorCount; ++$j){
                $colors[] = $out->getString();
            }
            $pieceTintColors[] = new PersonaPieceTintColor(
                $pieceType,
                $colors
            );
        }
        if($protocol >= ProtocolConstants::BEDROCK_1_17_30){
            $premium = $out->getBool();
            $persona = $out->getBool();
            $capeOnClassic = $out->getBool();
            $isPrimaryUser = $out->getBool();
        }

        return new SkinData($skinId, $skinPlayFabId, $skinResourcePatch, $skinData, $animations, $capeData, $geometryData, $geometryDataVersion ?? "1.17.30", $animationData, $capeId, $fullSkinId, $armSize, $skinColor, $personaPieces, $pieceTintColors, true, $premium ?? false, $persona ?? false, $capeOnClassic ?? false, $isPrimaryUser ?? true);
    }

    public static function putSkinImage(SkinImage $image, DataPacket $packet) : void{
		$in = new BinaryStream();
		$in->putLInt($image->getWidth());
		$in->putLInt($image->getHeight());
		$in->putString($image->getData());
    }

    public static function getSkinImage(DataPacket $packet) : SkinImage{
		$out = new BinaryStream();
		$width = $out->getLInt();
		$height = $out->getLInt();
		$data = $out->getString();
		try{
			return new SkinImage($height, $width, $data);
		}catch(\InvalidArgumentException $e){
			throw new PacketDecodeException($e->getMessage(), 0, $e);
		}
    }

    public static function putItemStack(PacketSerializer $packet, int $protocol, Item $item, callable $writeExtraCrapInTheMiddle) {
        if($item->getId() === 0){
            $packet->putVarInt(0);
            return;
        }

        $coreData = $item->getMeta();
        [$netId, $netData] = MultiVersionItemTranslator::getInstance()->toNetworkId($item->getId(), $coreData, $protocol);

		$packet->putVarInt($netId);
		$packet->putLShort($item->getCount());
		$packet->putUnsignedVarInt($netData);

        $writeExtraCrapInTheMiddle($packet);

        $blockRuntimeId = 0;
        $isBlockItem = $item->getId() < 256;
        if($isBlockItem){
            $block = $item->getBlock();
            if($block->getId() !== BlockLegacyIds::AIR){
                $blockRuntimeId = MultiVersionRuntimeBlockMapping::toStaticRuntimeId($block->getId(), $block->getMeta(), $protocol);
            }
        }
        $packet->putVarInt($blockRuntimeId);

        $nbt = null;
        if($item->hasNamedTag()){
            $nbt = clone $item->getNamedTag();
        }
        if($item instanceof Durable and $coreData > 0){
            if($nbt !== null){
                if(($existing = $nbt->getTag("Meta")) !== null){
                    $nbt->removeTag("Meta");
                    $nbt->setTag("___Meta_ProtocolCollisionResolution___", $existing);
                }
            }else{
                $nbt = new CompoundTag();
            }
            $nbt->setInt("Meta", $coreData);
        }elseif($isBlockItem && $coreData !== 0){
            //TODO HACK: This foul-smelling code ensures that we can correctly deserialize an item when the
            //client sends it back to us, because as of 1.16.220, blockitems quietly discard their metadata
            //client-side. Aside from being very annoying, this also breaks various server-side behaviours.
            if($nbt === null){
                $nbt = new CompoundTag();
            }
            $nbt->setInt("___Meta___", $coreData);
        }

		$context = new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary($protocol));
		$packet->putString(
            (static function() use ($protocol, $nbt, $netId, $context) : string{
                $extraData = PacketSerializer::encoder($context);

                if($nbt !== null){
                    $extraData->putLShort(0xffff);
                    $extraData->putByte(1); //TODO: NBT data version (?)
                    $extraData->put((new LittleEndianNbtSerializer())->write(new TreeRoot($nbt)));
                }else{
                    $extraData->putLShort(0);
                }

                $extraData->putLInt(0); //CanPlaceOn entry count (TODO)
                $extraData->putLInt(0); //CanDestroy entry count (TODO)

                if($netId === MultiVersionGlobalItemTypeDictionary::getInstance()->getDictionary($protocol)->fromStringId("minecraft:shield", $protocol)){
                    $extraData->putLLong(0); //"blocking tick" (ffs mojang)
                }
                return $extraData->getBuffer();
            })());
    }

    public static function putItem(PacketSerializer $packet, int $protocol, Item $item, int $stackId) {
        self::putItemStack($packet, $protocol, $item, function(BinaryStream $out) use ($stackId){
            $out->putBool($stackId !== 0);
            if($stackId !== 0) {
                $out->putVarInt($stackId);
            }
        });
    }

    public static function putRecipeIngredient(PacketSerializer $packet, Item $item, int $protocol) {
        if($item->isNull()){
            $packet->putVarInt(0);
        }else{
            if($item->hasAnyDamageValue()){
                [$netId, ] = MultiVersionItemTranslator::getInstance()->toNetworkId($item->getId(), 0, $protocol);
                $netData = 0x7fff;
            }else{
                [$netId, $netData] = MultiVersionItemTranslator::getInstance()->toNetworkId($item->getId(), $item->getMeta(), $protocol);
            }
            $packet->putVarInt($netId);
            $packet->putVarInt($netData);
            $packet->putVarInt($item->getCount());
        }
    }

    public static function putItemStackWithoutStackId(PacketSerializer $packet, Item $item, int $protocol) : void{
        self::putItemStack($packet, $protocol, $item, function() : void{});
    }

    public static function getItemStack(DataPacket $packet, \Closure $readExtraCrapInTheMiddle, int $protocol) : Item{
		$out = new BinaryStream();
        $netId = $out->getVarInt();
        if($netId === 0){
            return ItemFactory::getInstance()->get(0, 0, 0);
        }

        $cnt = $out->getLShort();
        $netData = $out->getUnsignedVarInt();

        $null = null;
        [$id, $meta] = MultiVersionItemTranslator::getInstance()->fromNetworkId($netId, $netData, $null, $protocol);

        $readExtraCrapInTheMiddle($packet);

        $out->getVarInt();

        $extraData = PacketSerializer::decoder($out->getString(), 0, new PacketSerializerContext(MultiVersionGlobalItemTypeDictionary::getInstance()->getDictionary($protocol)));
        return (static function() use ($protocol, $extraData, $netId, $id, $meta, $cnt) : Item{
            $nbtLen = $extraData->getLShort();

            /** @var CompoundTag|null $nbt */
            $nbt = null;
            if($nbtLen === 0xffff){
                $nbtDataVersion = $extraData->getByte();
                if($nbtDataVersion !== 1){
                    throw new \UnexpectedValueException("Unexpected NBT data version $nbtDataVersion");
                }
				$offset = $extraData->getOffset();
				$decodedNBT = (new LittleEndianNbtSerializer())->read($extraData->getBuffer(), $offset, 512);
                $nbt = $decodedNBT;
            }elseif($nbtLen !== 0){
                throw new \UnexpectedValueException("Unexpected fake NBT length $nbtLen");
            }

            //TODO
            for($i = 0, $canPlaceOn = $extraData->getLInt(); $i < $canPlaceOn; ++$i){
                $extraData->get($extraData->getLShort());
            }

            //TODO
            for($i = 0, $canDestroy = $extraData->getLInt(); $i < $canDestroy; ++$i){
                $extraData->get($extraData->getLShort());
            }

            if($netId === MultiVersionGlobalItemTypeDictionary::getInstance()->getDictionary($protocol)->fromStringId("minecraft:shield", $protocol)){
                $extraData->getLLong(); //"blocking tick" (ffs mojang)
            }

            if(!$extraData->feof()){
                throw new \UnexpectedValueException("Unexpected trailing extradata for network item $netId");
            }

            if($nbt !== null){
                if($nbt->getTag("Meta") !== null){
                    $meta = $nbt->getInt("Damage");
                    $nbt->removeTag("Damage");
                    if(($conflicted = $nbt->getTag("___Meta_ProtocolCollisionResolution___")) !== null){
                        $nbt->removeTag("___Meta_ProtocolCollisionResolution___");
                        $nbt->setTag("Meta", $conflicted);
                    }elseif($nbt->count() === 0){
                        $nbt = null;
                    }
                }elseif(($metaTag = $nbt->getTag("___Meta___")) instanceof IntTag){
                    //TODO HACK: This foul-smelling code ensures that we can correctly deserialize an item when the
                    //client sends it back to us, because as of 1.16.220, blockitems quietly discard their metadata
                    //client-side. Aside from being very annoying, this also breaks various server-side behaviours.
                    $meta = $metaTag->getValue();
                    $nbt->removeTag("___Meta___");
                    if($nbt->count() === 0){
                        $nbt = null;
                    }
                }
            }
            return ItemFactory::getInstance()->get($id, $meta, $cnt, $nbt);
        })();
    }

    public static function getItemStackWrapper(DataPacket $packet, int $protocol): ItemStackWrapper{
        $stackId = 0;
        $stack = self::getItemStack($packet, function(PacketSerializer $in) use (&$stackId) : void{
            $hasNetId = $in->getBool();
            if($hasNetId){
                $stackId = $in->readGenericTypeNetworkId();
            }
        }, $protocol);
        return new ItemStackWrapper($stackId, new ItemStack($stack->getId(), $stack->getMeta(), $stack->getCount(), MultiVersionRuntimeBlockMapping::toStaticRuntimeId($stack->getId(), $stack->getMeta()), $stack->getNamedTag(), $stack->getCanPlaceOn(), $stack->getCanDestroy()));
    }

    public static function putEntityLink(EntityLink $link) {
		$out = new BinaryStream();
		$out->putActorUniqueId($link->fromActorUniqueId);
		$out->putActorUniqueId($link->toActorUniqueId);
		$out->putByte($link->type);
		$out->putBool($link->immediate);
		$out->putBool($link->causedByRider);
    }

    public static function putGameRules(array $rules, int $protocol){
		$out = new BinaryStream();
		$out->putUnsignedVarInt(count($rules));
        foreach($rules as $name => $rule){
			$out->putString($name);
            if($protocol >= ProtocolConstants::BEDROCK_1_17_0){
				$out->putBool($rule[2]);
            }
			$out->putUnsignedVarInt($rule[0]);
            switch($rule[0]){
                case GameRuleType::BOOL:
					$out->putBool($rule[1]);
                    break;
                case GameRuleType::INT:
					$out->putUnsignedVarInt($rule[1]);
                    break;
                case GameRuleType::FLOAT:
					$out->putLFloat($rule[1]);
                    break;
            }
        }
    }

}