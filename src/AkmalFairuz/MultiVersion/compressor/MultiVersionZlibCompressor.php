<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion\compressor;

use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;

class MultiVersionZlibCompressor {

    use SingletonTrait;

	public const DEFAULT_LEVEL = 7;
	public const DEFAULT_THRESHOLD = 256;
	public const DEFAULT_MAX_DECOMPRESSION_SIZE = 2 * 1024 * 1024;

	/**
	 * @see SingletonTrait::make()
	 */
	private static function make() : self{
		return new self(self::DEFAULT_LEVEL, self::DEFAULT_THRESHOLD, self::DEFAULT_MAX_DECOMPRESSION_SIZE);
	}

	/** @var int */
	private $level;
	/** @var int */
	private $threshold;
	/** @var int */
	private $maxDecompressionSize;

	public function __construct(int $level, int $minCompressionSize, int $maxDecompressionSize){
		$this->level = $level;
		$this->threshold = $minCompressionSize;
		$this->maxDecompressionSize = $maxDecompressionSize;
	}

	public function willCompress(string $data) : bool{
		return $this->threshold > -1 && strlen($data) >= $this->threshold;
	}

	/**
	 * @throws DecompressionException
	 */
	public function decompress(string $payload) : string{
		$result = @zlib_decode($payload, $this->maxDecompressionSize);
		if($result === false){
			throw new DecompressionException("Failed to decompress data");
		}
		return $result;
	}

	private static function zlib_encode(string $data, int $level) : string{
		return Utils::assumeNotFalse(zlib_encode($data, ZLIB_ENCODING_RAW, $level), "ZLIB compression failed");
	}

	public function compress(string $payload) : string{
		if(function_exists('libdeflate_deflate_compress')){
			return $this->willCompress($payload) ?
				libdeflate_deflate_compress($payload, $this->level) :
				self::zlib_encode($payload, 0);
		}
		return self::zlib_encode($payload, $this->willCompress($payload) ? $this->level : 0);
	}

    public static function new() : self{
        $ref = new \ReflectionClass(self::getInstance());
        $method = $ref->getMethod("make");
        $method->setAccessible(true);
        return $method->invoke(self::getInstance(), "");
    }
}