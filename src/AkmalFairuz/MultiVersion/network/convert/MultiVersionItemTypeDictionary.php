<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion\network\convert;

use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use function array_key_exists;

class MultiVersionItemTypeDictionary{

	/**
	 * @var ItemTypeEntry[][]
	 * @phpstan-var list<ItemTypeEntry>
	 */
	private array $itemTypes;
	/**
	 * @var string[][]
	 * @phpstan-var array<int, string>
	 */
	private array $intToStringIdMap = [];
	/**
	 * @var int[][]
	 * @phpstan-var array<string, int>
	 */
	private array $stringToIntMap = [];

	/**
	 * @param ItemTypeEntry[] $itemTypes
	 */
	public function __construct(array $itemTypes, int $protocol){
		$this->itemTypes[$protocol] = $itemTypes;
		foreach($this->itemTypes[$protocol] as $type){
			foreach ($type as $types){
				$this->stringToIntMap[$protocol][$types->getStringId()] = $types->getNumericId();
				$this->intToStringIdMap[$protocol][$types->getNumericId()] = $types->getStringId();
			}
		}
	}

	/**
	 * @return ItemTypeEntry[]
	 * @phpstan-return list<ItemTypeEntry>
	 */
	public function getEntries(int $protocol) : array{
		return $this->itemTypes[$protocol];
	}

	public function getAllEntries(): array{
		return $this->itemTypes;
	}

	public function fromStringId(string $stringId, int $protocol) : int{
		if(!array_key_exists($stringId, $this->stringToIntMap[$protocol])){
			throw new \InvalidArgumentException("Unmapped string ID \"$stringId\"");
		}
		return $this->stringToIntMap[$protocol][$stringId];
	}

	public function fromIntId(int $intId, int $protocol) : string{
		if(!array_key_exists($intId, $this->intToStringIdMap[$protocol])){
			throw new \InvalidArgumentException("Unmapped int ID $intId");
		}
		return $this->intToStringIdMap[$protocol][$intId];
	}
}