<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion\network\convert;

use AkmalFairuz\MultiVersion\Loader;
use AkmalFairuz\MultiVersion\network\ProtocolConstants;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use Webmozart\PathUtil\Path;

final class MultiVersionGlobalItemTypeDictionary {
	use SingletonTrait;

	/**
	 * @var MultiVersionItemTypeDictionary[]
	 */
	private static $dictionary = [];

	const PROTOCOL = [
		ProtocolConstants::BEDROCK_1_16_220 => "_1_16_220",
		ProtocolConstants::BEDROCK_1_17_0 => "_1_17_0",
		ProtocolConstants::BEDROCK_1_17_10 => "_1_17_10",
		ProtocolConstants::BEDROCK_1_17_30 => "_1_17_30",
		ProtocolConstants::BEDROCK_1_17_40 => "_1_17_40",
		ProtocolConstants::BEDROCK_1_18_0 => "_1_18_0",
	];

	private static function make() : self{
		$dictionary = [];
		$p = -1;
		foreach(self::PROTOCOL as $protocol => $file){
			if(Loader::getInstance()->isProtocolDisabled($protocol)) {
				continue;
			}
			$data = Utils::assumeNotFalse(file_get_contents(Path::join(Loader::$resourcesPath, "vanilla/required_item_list$file.json")), "Missing required resource file");
			$table = json_decode($data, true);
			if(!is_array($table)){
				throw new AssumptionFailedError("Invalid item list format");
			}

			$params = [];
			foreach($table as $name => $entry){
				if(!is_array($entry) || !is_string($name) || !isset($entry["component_based"], $entry["runtime_id"]) || !is_bool($entry["component_based"]) || !is_int($entry["runtime_id"])){
					throw new AssumptionFailedError("Invalid item list format");
				}
				$params[] = new ItemTypeEntry($name, $entry["runtime_id"], $entry["component_based"]);
			}
			$dictionary[$protocol] = $params;
			$p = $protocol;
		}
		self::$dictionary[$p] = new MultiVersionItemTypeDictionary($dictionary, $p);
		return new self;
	}

	public function getDictionary(int $protocol = ProtocolInfo::CURRENT_PROTOCOL) : MultiVersionItemTypeDictionary{ return self::$dictionary[$protocol]; }

	/**
	 * @return MultiVersionItemTypeDictionary[]
	 */
	public function getAllDictionaries(): MultiVersionItemTypeDictionary{ return self::$dictionary; }
}