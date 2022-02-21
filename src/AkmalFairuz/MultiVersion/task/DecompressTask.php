<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion\task;

use AkmalFairuz\MultiVersion\Loader;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\utils\BinaryStream;
use function zlib_decode;

class DecompressTask extends AsyncTask{

    /** @var string */
    private $buffer;

    /** @var bool */
    private $fail = false;

	private const TLS_KEY_PROMISE = "promise";
	private const TLS_KEY_ERROR_HOOK = "errorHook";

    public function __construct(BinaryStream $packet, callable $callback) {
        $packet->setOffset(0);
        $packet->getByte();
        $this->buffer = $packet->getRemaining();
        $this->storeLocal(self::TLS_KEY_PROMISE, $packet);
        $this->storeLocal(self::TLS_KEY_ERROR_HOOK, $callback);
    }

    public function onRun(): void{
        try{
            $this->setResult(zlib_decode($this->buffer, 1024 * 1024 * 2));
        } catch(\Exception $e) {
            $this->fail = true;
        }
    }

    public function onCompletion(): void{
        if($this->fail) {
            Loader::getInstance()->getLogger()->error("Failed to decompress batch packet");
            return;
        }
		/** @var CompressBatchPromise $promise */
		[$packet, $callback] = $this->fetchLocal(self::TLS_KEY_PROMISE);
		$promise->resolve($this->getResult());
        $packet->offset = 0;
        $packet->payload = $this->getResult();
        $callback($packet);
    }
}
