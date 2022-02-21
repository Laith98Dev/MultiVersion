<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion\network\convert;

use AkmalFairuz\MultiVersion\Loader;
use AkmalFairuz\MultiVersion\network\ProtocolConstants;
use AkmalFairuz\MultiVersion\network\Translator;
use pocketmine\crafting\CraftingManager;
use pocketmine\crafting\FurnaceType;
use pocketmine\item\Item;
use pocketmine\network\mcpe\convert\ItemTranslator;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\recipe\CraftingRecipeBlockName;
use pocketmine\network\mcpe\protocol\types\recipe\FurnaceRecipe as ProtocolFurnaceRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\FurnaceRecipeBlockName;
use pocketmine\network\mcpe\protocol\types\recipe\PotionContainerChangeRecipe as ProtocolPotionContainerChangeRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\PotionTypeRecipe as ProtocolPotionTypeRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeIngredient;
use pocketmine\network\mcpe\protocol\types\recipe\ShapedRecipe as ProtocolShapedRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\ShapelessRecipe as ProtocolShapelessRecipe;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Binary;
use Ramsey\Uuid\Uuid;

class MultiVersionCraftingManager extends CraftingManager{

	/**
	 * @var CraftingDataPacket[][]
	 * @phpstan-var array<int, CraftingDataPacket>
	 */
	private $caches = [];

    const PROTOCOL = [
        ProtocolConstants::BEDROCK_1_18_0,
        ProtocolConstants::BEDROCK_1_17_40,
        ProtocolConstants::BEDROCK_1_17_30,
        ProtocolConstants::BEDROCK_1_17_10,
        ProtocolConstants::BEDROCK_1_17_0,
        ProtocolConstants::BEDROCK_1_16_220
    ];

	public function getCache(CraftingManager $manager, int $protocol) : CraftingDataPacket{
		$id = spl_object_id($manager);
		if(!isset($this->caches[$protocol][$id])){
			$manager->getDestructorCallbacks()->add(function() use ($id, $protocol) : void{
				unset($this->caches[$protocol][$id]);
			});
			$manager->getRecipeRegisteredCallbacks()->add(function() use ($id, $protocol) : void{
				unset($this->caches[$protocol][$id]);
			});
			$this->caches[$protocol][$id] = $this->buildCraftingDataCache($manager);
		}
		return $this->caches[$protocol][$id];
	}

	/**
	 * Rebuilds the cached CraftingDataPacket.
	 */
	private function buildCraftingDataCache(CraftingManager $manager) : CraftingDataPacket{
		Timings::$craftingDataCacheRebuild->startTiming();

		$counter = 0;
		$nullUUID = Uuid::fromString(Uuid::NIL);
		$converter = TypeConverter::getInstance();
		$recipesWithTypeIds = [];
		foreach($manager->getShapelessRecipes() as $list){
			foreach($list as $recipe){
				$recipesWithTypeIds[] = new ProtocolShapelessRecipe(
					CraftingDataPacket::ENTRY_SHAPELESS,
					Binary::writeInt(++$counter),
					array_map(function(Item $item) use ($converter) : RecipeIngredient{
						return $converter->coreItemStackToRecipeIngredient($item);
					}, $recipe->getIngredientList()),
					array_map(function(Item $item) use ($converter) : ItemStack{
						return $converter->coreItemStackToNet($item);
					}, $recipe->getResults()),
					$nullUUID,
					CraftingRecipeBlockName::CRAFTING_TABLE,
					50,
					$counter
				);
			}
		}
		foreach($manager->getShapedRecipes() as $list){
			foreach($list as $recipe){
				$inputs = [];

				for($row = 0, $height = $recipe->getHeight(); $row < $height; ++$row){
					for($column = 0, $width = $recipe->getWidth(); $column < $width; ++$column){
						$inputs[$row][$column] = $converter->coreItemStackToRecipeIngredient($recipe->getIngredient($column, $row));
					}
				}
				$recipesWithTypeIds[] = $r = new ProtocolShapedRecipe(
					CraftingDataPacket::ENTRY_SHAPED,
					Binary::writeInt(++$counter),
					$inputs,
					array_map(function(Item $item) use ($converter) : ItemStack{
						return $converter->coreItemStackToNet($item);
					}, $recipe->getResults()),
					$nullUUID,
					CraftingRecipeBlockName::CRAFTING_TABLE,
					50,
					$counter
				);
			}
		}

		foreach(FurnaceType::getAll() as $furnaceType){
			$typeTag = match($furnaceType->id()){
				FurnaceType::FURNACE()->id() => FurnaceRecipeBlockName::FURNACE,
				FurnaceType::BLAST_FURNACE()->id() => FurnaceRecipeBlockName::BLAST_FURNACE,
				FurnaceType::SMOKER()->id() => FurnaceRecipeBlockName::SMOKER,
				default => throw new AssumptionFailedError("Unreachable"),
			};
			foreach($manager->getFurnaceRecipeManager($furnaceType)->getAll() as $recipe){
				$input = $converter->coreItemStackToNet($recipe->getInput());
				$recipesWithTypeIds[] = new ProtocolFurnaceRecipe(
					CraftingDataPacket::ENTRY_FURNACE_DATA,
					$input->getId(),
					$input->getMeta(),
					$converter->coreItemStackToNet($recipe->getResult()),
					$typeTag
				);
			}
		}

		$potionTypeRecipes = [];
		foreach($manager->getPotionTypeRecipes() as $recipes){
			foreach($recipes as $recipe){
				$input = $converter->coreItemStackToNet($recipe->getInput());
				$ingredient = $converter->coreItemStackToNet($recipe->getIngredient());
				$output = $converter->coreItemStackToNet($recipe->getOutput());
				$potionTypeRecipes[] = new ProtocolPotionTypeRecipe(
					$input->getId(),
					$input->getMeta(),
					$ingredient->getId(),
					$ingredient->getMeta(),
					$output->getId(),
					$output->getMeta()
				);
			}
		}

		$potionContainerChangeRecipes = [];
		$itemTranslator = MultiVersionItemTranslator::getInstance();
		foreach (self::PROTOCOL as $protocol => $file){
			if(Loader::getInstance()->isProtocolDisabled($protocol)) {
				continue;
			}
			foreach($manager->getPotionContainerChangeRecipes() as $recipes){
				foreach($recipes as $recipe){
					$input = $itemTranslator->toNetworkId($recipe->getInputItemId(), 0, $protocol);
					$ingredient = $itemTranslator->toNetworkId($recipe->getIngredient()->getId(), 0, $protocol);
					$output = $itemTranslator->toNetworkId($recipe->getOutputItemId(), 0, $protocol);
					$potionContainerChangeRecipes[] = new ProtocolPotionContainerChangeRecipe(
						$input[0],
						$ingredient[0],
						$output[0]
					);
				}
			}
		}

		Timings::$craftingDataCacheRebuild->stopTiming();
		return CraftingDataPacket::create($recipesWithTypeIds, $potionTypeRecipes, $potionContainerChangeRecipes, [], true);
	}

    public function getCraftingDataPacketA(int $protocol): BatchPacket{
        return $this->multiVersionCraftingDataCache[$protocol];
    }
}