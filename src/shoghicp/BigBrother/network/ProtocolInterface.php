<?php
/**
 *  ______  __         ______               __    __
 * |   __ \|__|.-----.|   __ \.----..-----.|  |_ |  |--..-----..----.
 * |   __ <|  ||  _  ||   __ <|   _||  _  ||   _||     ||  -__||   _|
 * |______/|__||___  ||______/|__|  |_____||____||__|__||_____||__|
 *             |_____|
 *
 * BigBrother plugin for PocketMine-MP
 * Copyright (C) 2014-2015 shoghicp <https://github.com/shoghicp/BigBrother>
 * Copyright (C) 2016- BigBrotherTeam
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * @author BigBrotherTeam
 * @link   https://github.com/BigBrotherTeam/BigBrother
 *
 */

namespace shoghicp\BigBrother\network;

use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\SourceInterface;
use pocketmine\Server;
use pocketmine\Player;
use pocketmine\utils\MainLogger;
use shoghicp\BigBrother\BigBrother;
use shoghicp\BigBrother\DesktopPlayer;
use shoghicp\BigBrother\network\Info; //Computer Edition
use shoghicp\BigBrother\network\Packet;
use shoghicp\BigBrother\network\protocol\Login\EncryptionResponsePacket;
use shoghicp\BigBrother\network\protocol\Login\LoginStartPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\AdvancementTabPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\EnchantItemPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\TeleportConfirmPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\AnimatePacket;
use shoghicp\BigBrother\network\protocol\Play\Client\ConfirmTransactionPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\ClickWindowPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\ClientSettingsPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\ClientStatusPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\CreativeInventoryActionPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\EntityActionPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\PlayerAbilitiesPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\ChatPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\CloseWindowPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\HeldItemChangePacket;
use shoghicp\BigBrother\network\protocol\Play\Client\KeepAlivePacket;
use shoghicp\BigBrother\network\protocol\Play\Client\PlayerBlockPlacementPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\PlayerDiggingPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\PlayerLookPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\PlayerPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\PlayerPositionAndLookPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\PlayerPositionPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\PluginMessagePacket;
use shoghicp\BigBrother\network\protocol\Play\Client\TabCompletePacket;
use shoghicp\BigBrother\network\protocol\Play\Client\UpdateSignPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\UseEntityPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\UseItemPacket;
use shoghicp\BigBrother\utils\Binary;

class ProtocolInterface implements SourceInterface{

	/** @var BigBrother */
	protected $plugin;
	/** @var Server */
	protected $server;
	/** @var Translator */
	protected $translator;
	/** @var ServerThread */
	protected $thread;

	/** @var \SplObjectStorage<DesktopPlayer> */
	protected $sessions;

	/** @var DesktopPlayer[] */
	protected $sessionsPlayers = [];

	/** @var DesktopPlayer[] */
	protected $identifiers = [];

	/** @var int */
	protected $identifier = 0;

	/** @var int */
	private $threshold;

	public function __construct(BigBrother $plugin, Server $server, Translator $translator, int $threshold){
		$this->plugin = $plugin;
		$this->server = $server;
		$this->translator = $translator;
		$this->threshold = $threshold;
		$this->thread = new ServerThread($server->getLogger(), $server->getLoader(), $plugin->getPort(), $plugin->getIp(), $plugin->getMotd(), $plugin->getDataFolder()."server-icon.png", false);
		$this->sessions = new \SplObjectStorage();
	}

	public function start(){
		$this->thread->start();
	}

	public function emergencyShutdown(){
		$this->thread->pushMainToThreadPacket(chr(ServerManager::PACKET_EMERGENCY_SHUTDOWN));
	}

	public function shutdown(){
		$this->thread->pushMainToThreadPacket(chr(ServerManager::PACKET_SHUTDOWN));
	}

	public function setName(string $name){
		$info = $this->plugin->getServer()->getQueryInformation();
		$value = [
			"MaxPlayers" => $info->getMaxPlayerCount(),
			"OnlinePlayers" => $info->getPlayerCount(),
		];
		$buffer = chr(ServerManager::PACKET_SET_OPTION).chr(strlen("name"))."name".json_encode($value);
		$this->thread->pushMainToThreadPacket($buffer);
	}

	public function closeSession(int $identifier){
		if(isset($this->sessionsPlayers[$identifier])){
			$player = $this->sessionsPlayers[$identifier];
			unset($this->sessionsPlayers[$identifier]);
			$player->close($player->getLeaveMessage(), "Connection closed");
		}
	}

	public function close(Player $player, string $reason = "unknown reason"){
		if(isset($this->sessions[$player])){
			$identifier = $this->sessions[$player];
			$this->sessions->detach($player);
			unset($this->identifiers[$identifier]);
			$this->thread->pushMainToThreadPacket(chr(ServerManager::PACKET_CLOSE_SESSION) . Binary::writeInt($identifier));
		}else{
			return;
		}
	}

	protected function sendPacket(int $target, Packet $packet){
		if(\pocketmine\DEBUG > 3){
			$id = bin2hex(chr($packet->pid()));
			if($id !== "1f"){
				echo "[Send][Interface] 0x".bin2hex(chr($packet->pid()))."\n";
			}
		}

		$data = chr(ServerManager::PACKET_SEND_PACKET) . Binary::writeInt($target) . $packet->write();
		$this->thread->pushMainToThreadPacket($data);
	}

	public function setCompression(DesktopPlayer $player){
		if(isset($this->sessions[$player])){
			$target = $this->sessions[$player];
			$data = chr(ServerManager::PACKET_SET_COMPRESSION) . Binary::writeInt($target) . Binary::writeInt($this->threshold);
			$this->thread->pushMainToThreadPacket($data);
		}
	}

	public function enableEncryption(DesktopPlayer $player, string $secret){
		if(isset($this->sessions[$player])){
			$target = $this->sessions[$player];
			$data = chr(ServerManager::PACKET_ENABLE_ENCRYPTION) . Binary::writeInt($target) . $secret;
			$this->thread->pushMainToThreadPacket($data);
		}
	}

	public function putRawPacket(DesktopPlayer $player, Packet $packet){
		if(isset($this->sessions[$player])){
			$target = $this->sessions[$player];
			$this->sendPacket($target, $packet);
		}
	}

	public function putPacket(Player $player, DataPacket $packet, bool $needACK = false, bool $immediate = true){
		$id = 0;
		if($needACK){
			$id = $this->identifier++;
			$this->identifiers[$id] = $player;
		}
		assert($player instanceof DesktopPlayer);
		$packets = $this->translator->serverToInterface($player, $packet);
		if($packets !== null and $this->sessions->contains($player)){
			$target = $this->sessions[$player];
			if(is_array($packets)){
				foreach($packets as $packet){
					$this->sendPacket($target, $packet);
				}
			}else{
				$this->sendPacket($target, $packets);
			}
		}

		return $id;
	}

	protected function receivePacket(DesktopPlayer $player, Packet $packet){
		$packets = $this->translator->interfaceToServer($player, $packet);
		if($packets !== null){
			if(is_array($packets)){
				foreach($packets as $packet){
					$player->handleDataPacket($packet);
				}
			}else{
				$player->handleDataPacket($packets);
			}
		}
	}

	protected function handlePacket(DesktopPlayer $player, string $payload){
		if(\pocketmine\DEBUG > 3){
			$id = bin2hex(chr(ord($payload{0})));
			if($id !== "0b"){//KeepAlivePacket
				echo "[Receive][Interface] 0x".bin2hex(chr(ord($payload{0})))."\n";
			}
		}

		$pid = ord($payload{0});
		$offset = 1;

		$status = $player->bigBrother_getStatus();

		if($status === 1){
			switch($pid){
				case InboundPacket::TELEPORT_CONFIRM_PACKET:
					$pk = new TeleportConfirmPacket();
					break;
				case InboundPacket::TAB_COMPLETE_PACKET:
					$pk = new TabCompletePacket();
					break;
				case InboundPacket::CHAT_PACKET:
					$pk = new ChatPacket();
					break;
				case InboundPacket::CLIENT_STATUS_PACKET:
					$pk = new ClientStatusPacket();
					break;
				case InboundPacket::CLIENT_SETTINGS_PACKET:
					$pk = new ClientSettingsPacket();
					break;
				case InboundPacket::CONFIRM_TRANSACTION_PACKET:
					$pk = new ConfirmTransactionPacket();
					break;
				case InboundPacket::ENCHANT_ITEM_PACKET:
					$pk = new EnchantItemPacket();
					break;
				case InboundPacket::CLICK_WINDOW_PACKET:
					$pk = new ClickWindowPacket();
					break;
				case InboundPacket::CLOSE_WINDOW_PACKET:
					$pk = new CloseWindowPacket();
					break;
				case InboundPacket::PLUGIN_MESSAGE_PACKET:
					$pk = new PluginMessagePacket();
					break;
				case InboundPacket::USE_ENTITY_PACKET:
					$pk = new UseEntityPacket();
					break;
				case InboundPacket::KEEP_ALIVE_PACKET:
					$pk = new KeepAlivePacket();
					break;
				case InboundPacket::PLAYER_PACKET:
					$pk = new PlayerPacket();
					break;
				case InboundPacket::PLAYER_POSITION_PACKET:
					$pk = new PlayerPositionPacket();
					break;
				case InboundPacket::PLAYER_POSITION_AND_LOOK_PACKET:
					$pk = new PlayerPositionAndLookPacket();
					break;
				case InboundPacket::PLAYER_LOOK_PACKET:
					$pk = new PlayerLookPacket();
					break;
				case InboundPacket::PLAYER_ABILITIES_PACKET:
					$pk = new PlayerAbilitiesPacket();
					break;
				case InboundPacket::PLAYER_DIGGING_PACKET:
					$pk = new PlayerDiggingPacket();
					break;
				case InboundPacket::ENTITY_ACTION_PACKET:
					$pk = new EntityActionPacket();
					break;
				case InboundPacket::ADVANCEMENT_TAB_PACKET:
					$pk = new AdvancementTabPacket();
					break;
				case InboundPacket::HELD_ITEM_CHANGE_PACKET:
					$pk = new HeldItemChangePacket();
					break;
				case InboundPacket::CREATIVE_INVENTORY_ACTION_PACKET:
					$pk = new CreativeInventoryActionPacket();
					break;
				case InboundPacket::UPDATE_SIGN_PACKET:
					$pk = new UpdateSignPacket();
					break;
				case InboundPacket::ANIMATE_PACKET:
					$pk = new AnimatePacket();
					break;
				case InboundPacket::PLAYER_BLOCK_PLACEMENT_PACKET:
					$pk = new PlayerBlockPlacementPacket();
					break;
				case InboundPacket::USE_ITEM_PACKET:
					$pk = new UseItemPacket();
					break;
				default:
					if(\pocketmine\DEBUG > 3){
						echo "[Receive][Interface] 0x".bin2hex(chr($pid))." Not implemented\n"; //Debug
					}
					return;
			}

			$pk->read($payload, $offset);
			$this->receivePacket($player, $pk);
		}elseif($status === 0){
			if($pid === InboundPacket::LOGIN_START_PACKET){
				$pk = new LoginStartPacket();
				$pk->read($payload, $offset);
				$player->bigBrother_handleAuthentication($this->plugin, $pk->name, $this->plugin->isOnlineMode());
			}elseif($pid === InboundPacket::ENCRYPTION_RESPONSE_PACKET and $this->plugin->isOnlineMode()){
				$pk = new EncryptionResponsePacket();
				$pk->read($payload, $offset);
				$player->bigBrother_processAuthentication($this->plugin, $pk);
			}else{
				$player->close($player->getLeaveMessage(), "Unexpected packet $pid");
			}
		}
	}

	public function process() : bool{
		if(count($this->identifiers) > 0){
			foreach($this->identifiers as $id => $player){
				$player->handleACK($id);
			}
		}

		while(strlen($buffer = $this->thread->readThreadToMainPacket()) > 0){
			$offset = 1;
			$pid = ord($buffer{0});

			if($pid === ServerManager::PACKET_SEND_PACKET){
				$id = Binary::readInt(substr($buffer, $offset, 4));
				$offset += 4;
				if(isset($this->sessionsPlayers[$id])){
					$payload = substr($buffer, $offset);
					try{
						$this->handlePacket($this->sessionsPlayers[$id], $payload);

					}catch(\Exception $e){
						if(\pocketmine\DEBUG > 1){
							$logger = $this->server->getLogger();
							if($logger instanceof MainLogger){
								$logger->debug("DesktopPacket 0x" . bin2hex($payload));
								$logger->logException($e);
							}
						}
					}
				}
			}elseif($pid === ServerManager::PACKET_OPEN_SESSION){
				$id = Binary::readInt(substr($buffer, $offset, 4));
				$offset += 4;
				if(isset($this->sessionsPlayers[$id])){
					continue;
				}
				$len = ord($buffer{$offset++});
				$address = substr($buffer, $offset, $len);
				$offset += $len;
				$port = Binary::readShort(substr($buffer, $offset, 2));

				$identifier = "$id:$address:$port";

				$player = new DesktopPlayer($this, $identifier, $address, $port, $this->plugin);
				$this->sessions->attach($player, $id);
				$this->sessionsPlayers[$id] = $player;
				$this->plugin->getServer()->addPlayer($identifier, $player);
			}elseif($pid === ServerManager::PACKET_CLOSE_SESSION){
				$id = Binary::readInt(substr($buffer, $offset, 4));
				$offset += 4;
				$flag = Binary::readInt(substr($buffer, $offset, 4));

				if(isset($this->sessionsPlayers[$id])){
					if($flag === 0){
						$this->close($this->sessionsPlayers[$id]);
					}else{
						$this->closeSession($id);
					}
				}
			}

		}

		return true;
	}
}
