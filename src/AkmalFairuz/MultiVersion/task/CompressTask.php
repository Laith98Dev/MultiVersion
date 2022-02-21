<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion\task;

use AkmalFairuz\MultiVersion\Loader;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\scheduler\AsyncTask;
use pocketmine\utils\BinaryStream;
use function zlib_encode;

class CompressTask extends AsyncTask{

    /** @var bool */
    private $fail = false;

    /** @var int */
    private $level;

	private const TLS_KEY_PROMISE = "promise";
	private const TLS_KEY_ERROR_HOOK = "errorHook";

	/** @var string */
	private $payload;

	public function __construct(BinaryStream $stream, callable $callback) {
		$stream->rewind();
		$promise = new CompressBatchPromise();
        $this->storeLocal(self::TLS_KEY_PROMISE, $promise);
        $this->storeLocal(self::TLS_KEY_ERROR_HOOK, $callback);
		$this->payload = $promise->getResult();
		$this->level = 7;
    }

    public function onRun(): void{
        try{
            $this->setResult(zlib_encode($this->payload, ZLIB_ENCODING_RAW, $this->level));
        } catch(\Exception $e) {
            $this->fail = true;
        }
    }

    public function onCompletion() : void{
        if($this->fail) {
            Loader::getInstance()->getLogger()->error("Failed to compress batch packet");
            return;
        }
		/** @var CompressBatchPromise $promise */
		[$packet, $callback] = $this->fetchLocal(self::TLS_KEY_PROMISE);
		$promise->resolve($this->getResult());
        $callback($packet);
    }
}
