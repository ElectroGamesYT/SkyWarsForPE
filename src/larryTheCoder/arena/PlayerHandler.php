<?php
/**
 * Adapted from the Wizardry License
 *
 * Copyright (c) 2015-2019 larryTheCoder and contributors
 *
 * Permission is hereby granted to any persons and/or organizations
 * using this software to copy, modify, merge, publish, and distribute it.
 * Said persons and/or organizations are not allowed to use the software or
 * any derivatives of the work for commercial use or any other means to generate
 * income, nor are they allowed to claim this software as their own.
 *
 * The persons and/or organizations are also disallowed from sub-licensing
 * and/or trademarking this software without explicit permission from larryTheCoder.
 *
 * Any persons and/or organizations using this software must disclose their
 * source code and have it publicly available, include this license,
 * provide sufficient credit to the original authors of the project (IE: larryTheCoder),
 * as well as provide a link to the original project.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
 * INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,FITNESS FOR A PARTICULAR
 * PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
 * LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
 * TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE
 * USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace larryTheCoder\arena;

use larryTheCoder\arena\api\ArenaState;
use larryTheCoder\arena\runtime\GameDebugger;
use larryTheCoder\SkyWarsPE;
use larryTheCoder\utils\Settings;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\sound\ClickSound;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\Player;

/**
 * Handles everything regarding the player.
 *
 * @package larryTheCoder\arenaRewrite
 */
trait PlayerHandler {

	//
	// Take note that these arrays (Player names) are stored in
	// lowercase letters. Use the function given to access the right player name
	//

	/** @var Player[] */
	private $players = [];
	/** @var Player[] */
	private $spectators = [];
	/** @var Level|null */
	private $arenaLevel;
	/** @var int[] */
	public $kills = [];

	/** @var bool */
	public $teamMode = false;
	/** @var int[] */
	private $teams = []; // "Player" => "Team color"
	/** @var int[] */
	public $configuredTeam = []; // "Team Color" => Counts
	/** @var int */
	public $maximumMembers = 0;
	/** @var int */
	public $maximumTeams = 0;
	/** @var int */
	public $minimumMembers = 0;

	public function getDebugger(): ?GameDebugger{
		return null;
	}

	public function addPlayer(Player $pl, int $team = -1){
		$this->getDebugger()->log("[PlayerHandler]: Adding player {$pl->getName()} into the list");

		# Set the player gamemode first
		$pl->setGamemode(0);
		$pl->getInventory()->clearAll();
		$pl->getArmorInventory()->clearAll();

		# Set the player health and food
		$pl->setMaxHealth(Settings::$joinHealth);
		$pl->setMaxHealth($pl->getMaxHealth());
		# just to be really sure
		if($pl->getAttributeMap() != null){
			$pl->setHealth(Settings::$joinHealth);
			$pl->setFood(20);
		}

		$this->players[strtolower($pl->getName())] = $pl;

		// Check if the arena is in team mode.
		if($this->teamMode){
			$this->teams[strtolower($pl->getName())] = $team;
		}
	}

	public function removePlayer(Player $pl): void{
		$this->getDebugger()->log("[PlayerHandler]: Removing player {$pl->getName()} from the list");

		// Check if the player do exists
		if(isset($this->players[strtolower($pl->getName())])){
			$this->getDebugger()->log("[PlayerHandler]: Condition fulfilled, removing the player from set.");

			unset($this->players[strtolower($pl->getName())]);

			// Unset the player from this team.
			if(isset($this->teams[strtolower($pl->getName())])){
				unset($this->teams[strtolower($pl->getName())]);

				$this->getDebugger()->log("[PlayerHandler]: Removing player from team data.");
			}
		}

		if(isset($this->spectators[strtolower($pl->getName())])){
			unset($this->spectators[strtolower($pl->getName())]);

			$this->getDebugger()->log("[PlayerHandler]: Removing player {$pl->getName()} from the spectator list");
		}
	}

	public function setSpectator(Player $pl): void{
		$this->getDebugger()->log("[PlayerHandler]: Adding player {$pl->getName()} into spectator list");

		$this->removePlayer($pl);
		$this->spectators[strtolower($pl->getName())] = $pl;
	}

	/**
	 * Set the player team for the user.
	 *
	 * @param Player $pl
	 * @param int $team
	 */
	public function setPlayerTeam(Player $pl, int $team){
		if(!$this->teamMode){
			return;
		}

		$this->teams[strtolower($pl->getName())] = $team;
	}

	public function broadcastToPlayers(string $msg, $popup = true, $toReplace = [], $replacement = []): void{
		$this->getDebugger()->log("[Arena Broadcast]): " . str_replace($toReplace, $replacement, SkyWarsPE::getInstance()->getMsg(null, $msg, false)));

		$inGame = array_merge($this->getPlayers(), $this->getSpectators());
		/** @var Player $p */
		foreach($inGame as $p){
			if($popup){
				$p->sendPopup(str_replace($toReplace, $replacement, SkyWarsPE::getInstance()->getMsg($p, $msg, false)));
			}else{
				$p->sendMessage(str_replace($toReplace, $replacement, SkyWarsPE::getInstance()->getMsg($p, $msg, false)));

				$p->getLevel()->addSound(new ClickSound($p));
			}
		}
	}

	/**
	 * Give the player the items that required in config.yml
	 * For spectator or NON-Spectator
	 *
	 * @param Player $p
	 * @param bool $spectate
	 */
	public function giveGameItems(Player $p, bool $spectate){
		if(!Settings::$enableSpecialItem){
			return;
		}
		foreach(Settings::$items as $item){
			/** @var Item $toGive */
			$toGive = $item[0];
			$placeAt = $item[1];
			$itemPermission = $item[2];
			$itemSpectate = $item[3];
			$giveAtWin = $item[6];
			# Set a new compound tag

			if($toGive->getId() === Item::FILLED_MAP){
				$tag = new CompoundTag();
				$tag->setTag(new CompoundTag("", []));
				$tag->setString("map_uuid", 18293883);
				$toGive->setNamedTag($tag);
			}
			if(empty($itemPermission) || $p->hasPermission($itemPermission)){
				if($giveAtWin && $this->getStatus() === ArenaState::STATE_ARENA_CELEBRATING){
					$p->getInventory()->setItem($placeAt, $toGive, true);
					continue;
				}
				if($spectate && $itemSpectate){
					$p->getInventory()->setItem($placeAt, $toGive, true);
					continue;
				}

				if(!$spectate && !$itemSpectate){
					$p->getInventory()->setItem($placeAt, $toGive, true);
					continue;
				} //Squid turning into kid
			}
		}
	}

	/**
	 * Return the default player name for this string. This function is perhaps
	 * to get the player information within this class.
	 *
	 * @param string $name
	 *
	 * @return string The original name of this player.
	 */
	public function getOriginName(string $name): ?string{
		if(!$this->isInArena($name)){
			return null;
		}

		return $this->getOriginPlayer($name)->getName();
	}

	/**
	 * Return the player class code for this string name. It checks if this handler
	 * stores its data inside the arrays.
	 *
	 * @param string $name
	 *
	 * @return Player The player itself.
	 */
	public function getOriginPlayer(string $name): ?Player{
		if(isset($this->players[strtolower($name)])){
			return $this->players[strtolower($name)];
		}elseif(isset($this->spectators[strtolower($name)])){
			return $this->spectators[strtolower($name)];
		}else{
			return null;
		}
	}

	/**
	 * Checks either the player is inside
	 * the arena or not.
	 *
	 * @param mixed $pl
	 *
	 * @return bool
	 */
	public function isInArena($pl): bool{
		if($pl instanceof Player){
			return isset($this->players[strtolower($pl->getName())]) || isset($this->spectators[strtolower($pl->getName())]);
		}elseif(is_string($pl)){
			return isset($this->players[strtolower($pl)]) || isset($this->spectators[strtolower($pl)]);
		}else{
			return false;
		}
	}

	/**
	 * Get the number of players in the arena.
	 * This returns the number of player that is alive
	 * inside the arena.
	 *
	 * @return int
	 */
	public function getPlayersCount(): int{
		return count($this->players);
	}

	/**
	 * @return Player[]
	 */
	public function getAllPlayers(): array{
		return array_merge($this->players, $this->spectators);
	}

	/**
	 * This will return the number of spectators
	 * that is inside the arena.
	 *
	 * @return int
	 */
	public function getSpectatorsCount(): int{
		return count($this->spectators);
	}

	/**
	 * Get the teams registered in this arena.
	 *
	 * @return int[]
	 */
	public function getTeams(): array{
		return $this->teams;
	}

	/**
	 * @return Player[]
	 */
	public function getPlayers(): array{
		return $this->players;
	}

	/**
	 * @return Player[]
	 */
	public function getSpectators(): array{
		return $this->spectators;
	}

	public function getPlayerState($sender): int{
		if($sender instanceof Player){
			if(isset($this->players[strtolower($sender->getName())])){
				return ArenaState::PLAYER_ALIVE;
			}elseif(isset($this->spectators[strtolower($sender->getName())])){
				return ArenaState::PLAYER_SPECTATE;
			}elseif($this->arenaLevel !== null && strtolower($sender->getLevel()->getName()) === strtolower($this->arenaLevel->getName())){
				return ArenaState::PLAYER_SPECIAL;
			}
		}

		return ArenaState::PLAYER_UNSET;
	}

	public function resetPlayers(){
		$this->getDebugger()->log("[PlayerHandler]: All players data is deleted.");

		/** @var Player $p */
		foreach(array_merge($this->players, $this->spectators) as $p){
			$p->removeAllEffects();
			$p->setMaxHealth(20);
			$p->setMaxHealth($p->getMaxHealth());
			if($p->getAttributeMap() != null){//just to be really sure
				$p->setHealth(20);
				$p->setFood(20);
			}
			$p->setXpLevel(0);
			$p->removeAllEffects();
			$p->getInventory()->clearAll(); // Possible issue here.
			$p->getArmorInventory()->clearAll();
			$p->setGamemode(Player::ADVENTURE);

			SkyWarsPE::getInstance()->getDatabase()->teleportLobby($p);
		}

		$this->spectators = [];
		$this->players = [];
		$this->teams = [];
		$this->kills = [];
	}
}