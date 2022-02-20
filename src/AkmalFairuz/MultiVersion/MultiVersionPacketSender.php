<?php

namespace AkmalFairuz\MultiVersion;

use pocketmine\network\mcpe\PacketSender;

class MultiVersionPacketSender implements PacketSender{

	public function send(string $payload, bool $immediate): void{}

	public function close(string $reason = "unknown reason"): void{}
}