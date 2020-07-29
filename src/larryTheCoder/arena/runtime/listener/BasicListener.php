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

namespace larryTheCoder\arena\runtime\listener;

use larryTheCoder\arena\Arena;
use larryTheCoder\arena\runtime\DefaultGameAPI;
use larryTheCoder\arena\runtime\GameDebugger;
use larryTheCoder\arena\State;
use larryTheCoder\player\PlayerData;
use larryTheCoder\SkyWarsPE;
use larryTheCoder\utils\Settings;
use larryTheCoder\utils\Utils;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerKickEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\level\Location;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\ClientboundMapItemDataPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\MapInfoRequestPacket;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\utils\Color;

/**
 * A very basic listener for the arena.
 * This concept is basically to handle player as-it-should be handled
 * by the arena itself.
 *
 * @package larryTheCoder\arena\api\listener
 */
class BasicListener implements Listener {

	/** @var DefaultGameAPI */
	private $gameAPI;
	/**@var Arena */
	private $arena;
	/** @var string[]|int[] */
	private $lastHit = [];
	/** @var int[] */
	private $cooldown = [];

	public function __construct(DefaultGameAPI $api){
		$this->gameAPI = $api;
		$this->arena = $api->arena;
	}

	public function getDebugger(): GameDebugger{
		return $this->arena->getDebugger();
	}

	/**
	 * Handles the player movement. During STATE_WAITING or STATE_SLOPE_WAITING,
	 * player wont be able to get out from the cage area, but were able to move within
	 * the cage.
	 *
	 * @param PlayerMoveEvent $e
	 * @priority MONITOR
	 */
	public function onMove(PlayerMoveEvent $e){
		$p = $e->getPlayer();
		if($this->arena->isInArena($p) && $this->arena->getStatus() <= State::STATE_SLOPE_WAITING && $p->isSurvival()){
			if(!isset($this->arena->usedPedestals[$p->getName()])){
				return;
			}

			/** @var Vector3 $loc */
			$loc = $this->arena->cageHandler->getCage($p);

			if(($loc->getY() - $e->getTo()->getY()) >= 1.55){
				$e->setTo(new Location($loc->getX(), $loc->getY(), $loc->getZ(), $p->yaw, $p->pitch, $p->getLevel()));
			}

			return;
		}
	}

	/**
	 * Handles player block placement in the arena. If the arena is not in STATE_ARENA_RUNNING,
	 * player wont be able to place blocks within the arena.
	 *
	 * @param BlockPlaceEvent $e
	 * @priority MONITOR
	 */
	public function onPlaceEvent(BlockPlaceEvent $e){
		$p = $e->getPlayer();
		if($this->arena->isInArena($p) && $p->isSurvival() && $this->arena->getStatus() !== State::STATE_ARENA_RUNNING){
			$e->setCancelled(true);
		}
	}

	/**
	 * Handles player block placement in the arena. If the arena is not in STATE_ARENA_RUNNING,
	 * player wont be able to place blocks within the arena.
	 *
	 * @param BlockBreakEvent $e
	 * @priority MONITOR
	 */
	public function onBreakEvent(BlockBreakEvent $e){
		$p = $e->getPlayer();
		if($this->arena->isInArena($p) && $p->isSurvival() && $this->arena->getStatus() !== State::STATE_ARENA_RUNNING){
			$e->setCancelled(true);
		}
	}

	/**
	 * Handles player damages towards another players. This event is to log player's damages towards
	 * another player entity. This is required to check who actually killed this player.
	 *
	 * @param EntityDamageEvent $e
	 * @priority HIGHEST
	 */
	public function onHit(EntityDamageEvent $e){
		$now = time();
		$entity = $e->getEntity();

		$player = $entity instanceof Player ? $entity : null;
		# Maybe this player is attacking a chicken
		if($player === null){
			return;
		}
		# Player must be inside of arena otherwise its a fake
		if(!$this->arena->isInArena($player)){
			return;
		}
		# Falling time isn't over yet
		if($this->gameAPI->fallTime !== 0){
			$e->setCancelled(true);

			return;
		}
		# Arena not running yet cancel it
		if($this->arena->getStatus() != State::STATE_ARENA_RUNNING){
			$e->setCancelled(true);

			return;
		}

		$this->getDebugger()->log("An entity {$player->getName()} is being attacked, cause ID: {$e->getCause()}");

		if(isset($this->cooldown[$player->getName()])){
			$this->getDebugger()->log("Under cooldown counter " . ($this->cooldown[$player->getName()] - $now) . "s.");
		}
		if(isset($this->lastHit[$player->getName()])){
			$this->getDebugger()->log("Last hit by: {$this->lastHit[$player->getName()]}.");
		}

		switch($e->getCause()){
			case EntityDamageEvent::CAUSE_ENTITY_ATTACK:
				if($e instanceof EntityDamageByEntityEvent){
					$damage = $e->getDamager();
					if($damage instanceof Player){
						$this->lastHit[$player->getName()] = $damage->getName();
						$this->cooldown[$player->getName()] = $now + 30;

						$this->getDebugger()->log("Damage is done by a player {$damage->getName()}");
					}else{
						$this->getDebugger()->log("Damage is done by an entity {$damage->getSaveId()}");
					}
				}else{
					$this->getDebugger()->log("Damage is done by an unknown entity.");

					if(isset($this->cooldown[$player->getName()])){
						if($this->cooldown[$player->getName()] - $now >= 0){
							break;
						}
						$this->lastHit[$player->getName()] = -1;// Member of illuminati?
						unset($this->cooldown[$player->getName()]);
						break;
					}
				}
				break;
			case EntityDamageEvent::CAUSE_PROJECTILE:
				if($e instanceof EntityDamageByChildEntityEvent){
					$damage = $e->getDamager();
					if($damage instanceof Player){
						$this->getDebugger()->log("Projectile damage is done by a player {$damage->getName()}.");

						$this->lastHit[$player->getName()] = $damage->getName();
						$this->cooldown[$player->getName()] = $now + 30;
						$volume = 0x10000000 * (min(30, 10) / 5); //No idea why such odd numbers, but this works...
						$damage->level->broadcastLevelSoundEvent($damage, LevelSoundEventPacket::SOUND_LEVELUP, 1, (int)$volume);
					}else{
						$this->getDebugger()->log("Projectile damage is done by an unknown entity.");
					}
				}else{
					$this->getDebugger()->log("Projectile damage is done by an unknown object.");

					$this->lastHit[$player->getName()] = $player->getName();
				}
				break;
			case EntityDamageEvent::CAUSE_MAGIC:
				if($e instanceof EntityDamageByEntityEvent || $e instanceof EntityDamageByChildEntityEvent){
					$damage = $e->getDamager();
					if($damage instanceof Player){
						$this->getDebugger()->log("Magic damage is done by a player {$damage->getName()}}.");

						$this->lastHit[$player->getName()] = $damage->getName();
						$this->cooldown[$player->getName()] = $now + 30;
					}else{
						$this->getDebugger()->log("Magic damage is done by an unknown entity {$damage->getNameTag()}.");
					}
				}else{
					$this->getDebugger()->log("Magic damage is done by an unknown object.");
					if(isset($this->cooldown[$player->getName()])){
						if($this->cooldown[$player->getName()] - $now >= 0){
							break;
						}
						$this->lastHit[$player->getName()] = $player->getNameTag();
						unset($this->cooldown[$player->getName()]);
						break;
					}
				}
				break;
			default:
				$this->getDebugger()->log("Unknown damage caused by the player.");

				if(isset($this->cooldown[$player->getName()])){
					if($this->cooldown[$player->getName()] - $now >= 0){
						break;
					}
					$this->lastHit[$player->getName()] = $e->getCause();
					unset($this->cooldown[$player->getName()]);
					break;
				}
				break;
		}
	}

	/**
	 * Handles player deaths. During this event, a piece of data that contains the last
	 * time player gets damaged from {@see BasicListener::onHit()} is analyzed and the message
	 * will get broadcasted to all of the players in the arena.
	 *
	 * @param PlayerDeathEvent $e
	 * @priority HIGH
	 */
	public function onPlayerDeath(PlayerDeathEvent $e){
		$p = $e->getPlayer();
		if($p instanceof Player && $this->arena->isInArena($p)){
			$e->setDeathMessage("");

			if($this->arena->getPlayerState($p) === State::PLAYER_ALIVE){
				$this->getDebugger()->log("A living player died in the arena.");

				$e->setDrops([]);
				# Set the database data
				$this->setDeathData($p);

				$player = !isset($this->lastHit[$p->getName()]) ? $p->getName() : $this->lastHit[$p->getName()];
				if(!is_integer($player)){
					$this->getDebugger()->log("This player is getting killed by {$player}.");
					if($player === $p->getName()){
						$this->arena->messageArenaPlayers('death-message-suicide', false, ["{PLAYER}"], [$p->getName()]);
					}else{
						$this->arena->messageArenaPlayers('death-message', false, ["{PLAYER}", "{KILLER}"], [$p->getName(), $player]);
						$this->arena->kills[$player]++;
					}
				}else{
					$this->getDebugger()->log("This player is getting killed with ID: {$player}.");

					$msg = Utils::getDeathMessageById($player);
					$this->arena->messageArenaPlayers($msg, false, ["{PLAYER}"], [$p->getName()]);
				}
				unset($this->lastHit[$p->getName()]);

				$this->arena->knockedOut($e);
			}else{
				// TODO: Check if the player is spectating?
			}
		}
	}

	private function setDeathData(Player $player){
		SkyWarsPE::$instance->getDatabase()->getPlayerData($player->getName(), function(PlayerData $pd) use ($player){
			$pd->death++;
			$pd->lost++;
			$pd->kill += $this->arena->kills[$player->getName()] ?? 0;
			$pd->time += (microtime(true) - $this->arena->startedTime);

			SkyWarsPE::$instance->getDatabase()->setPlayerData($player->getName(), $pd);
		});
	}

	/**
	 * Handles player's respawn after player's death. During this event, they will check
	 * if the player is in spectator mode, as shown in {@see Arena::knockedOut()}, otherwise
	 * we set the player respawn point to the lobby.
	 *
	 * @param PlayerRespawnEvent $e
	 * @priority MONITOR
	 */
	public function onRespawn(PlayerRespawnEvent $e){
		$p = $e->getPlayer();
		# Player must be inside of arena otherwise its a fake
		if(!$this->arena->isInArena($p)){
			var_dump("Not in arena..");

			return;
		}

		if($this->arena->getPlayerState($p) === State::PLAYER_SPECTATE){
			var_dump("Player respawned event...");

			$p->setXpLevel(0);
			if($this->arena->enableSpectator){
				$e->setRespawnPosition(Position::fromObject($this->arena->arenaSpecPos, $this->arena->getLevel()));
				$p->setGamemode(Player::SPECTATOR);
				$p->sendMessage($this->gameAPI->plugin->getMsg($p, 'player-spectate'));
				$this->gameAPI->giveGameItems($p, true);

				foreach($this->arena->getPlayers() as $p2){
					/** @var Player $d */
					if(($d = Server::getInstance()->getPlayer($p2)) instanceof Player){
						$d->hidePlayer($p);
					}
				}

				return;
			}
		}

		var_dump("Teleporting to lobby instead");
		SkyWarsPE::$instance->getDatabase()->teleportLobby($p);
	}

	/**
	 * Handles player interaction with the arena signs.
	 *
	 * @param PlayerInteractEvent $e
	 * @priority NORMAL
	 */
	public function onBlockTouch(PlayerInteractEvent $e){
		Utils::loadFirst($this->arena->joinSignWorld, true);

		$p = $e->getPlayer();
		$b = $e->getBlock();

		# Player is interacting with game signs
		if($b->equals(Position::fromObject($this->arena->joinSignVec, Server::getInstance()->getLevelByName($this->arena->joinSignWorld)))){
			$this->arena->joinToArena($p);
		}
	}

	public function playerQuitEvent(PlayerQuitEvent $event){
		$pl = $event->getPlayer();
		if($this->arena->isInArena($event->getPlayer())){
			$this->arena->leaveArena($event->getPlayer(), true);
			$this->arena->checkAlive();
		}
	}

	public function playerKickedEvent(PlayerKickEvent $event){
		if($this->arena->isInArena($event->getPlayer())){
			$this->arena->leaveArena($event->getPlayer(), true);
			$this->arena->checkAlive();
		}
	}

	public function onDataPacket(DataPacketReceiveEvent $event){
		$packet = $event->getPacket();
		$p = $event->getPlayer();
		if($packet instanceof MapInfoRequestPacket){
			if($packet->mapId === 18293883){
				$folder = SkyWarsPE::getInstance()->getDataFolder() . "image/";
				$colors = [];
				$imaged = @imagecreatefrompng($folder . "map.png");
				if(!$imaged){
					$this->getDebugger()->log("Error: Cannot load map");

					return;
				}
				$anchor = 128;
				$altars = 128;
				$imaged = imagescale($imaged, $anchor, $altars, IMG_NEAREST_NEIGHBOUR);
				imagepng($imaged, $folder . "map.png");
				for($y = 0; $y < $altars; ++$y){
					for($x = 0; $x < $anchor; ++$x){
						$rgb = imagecolorat($imaged, $x, $y);
						$color = imagecolorsforindex($imaged, $rgb);
						$r = $color["red"];
						$g = $color["green"];
						$b = $color["blue"];
						$colors[$y][$x] = new Color($r, $g, $b, 0xff);
					}
				}

				$pk = new ClientboundMapItemDataPacket();
				$pk->mapId = 18293883;
				$pk->type = ClientboundMapItemDataPacket::BITFLAG_TEXTURE_UPDATE;
				$pk->height = 128;
				$pk->width = 128;
				$pk->scale = 1;
				$pk->colors = $colors;
				$p->dataPacket($pk);
			}
		}
	}

	/**
	 * Handles player commands thru a severe permissions checks.
	 * This prevents the player from using a command that is forbidden to this game.
	 *
	 * @param PlayerCommandPreprocessEvent $ev
	 */
	public function onCommand(PlayerCommandPreprocessEvent $ev){
		$cmd = strtolower($ev->getMessage());
		$p = $ev->getPlayer();
		if($cmd{0} == '/'){
			$cmd = explode(' ', $cmd)[0];
			// In arena, no permission, is alive, arena started === cannot use command.
			$val = $this->arena->isInArena($p)
				&& !$p->hasPermission("sw.admin.bypass")
				&& $this->arena->getPlayerState($p) === State::PLAYER_ALIVE
				&& $this->arena->getStatus() === State::STATE_ARENA_RUNNING;
			if($val){
				if(!in_array($cmd, Settings::$acceptedCommand) && $cmd !== "sw"){
					$ev->getPlayer()->sendMessage($this->gameAPI->plugin->getMsg($p, "banned-command"));
					$ev->setCancelled(true);
				}
			}
		}

		unset($cmd);
	}

}