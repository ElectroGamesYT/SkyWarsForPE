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

use DateTime;
use larryTheCoder\arena\api\GameAPI;
use larryTheCoder\arena\runtime\CageHandler;
use larryTheCoder\arena\runtime\DefaultGameAPI;
use larryTheCoder\arena\runtime\GameDebugger;
use larryTheCoder\arena\runtime\tasks\PlayerDeathTask;
use larryTheCoder\SkyWarsPE;
use larryTheCoder\utils\Utils;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\scheduler\Task;
use pocketmine\scheduler\TaskHandler;
use pocketmine\Server;
use pocketmine\utils\Config;
use pocketmine\utils\Timezone;

/**
 * Presenting a custom implementation of arena-game-code. You can do
 * anything within this arena as long the API exists and valid. This class provides
 * the function to store players data, pedestals info, arena data, team info and player's gameplay
 * data itself.
 *
 * This code is separated from the codebase to implement better gameplay in the future.
 * Better statuses and consistence variables.
 *
 * This class holds these information:
 * - Extensive spawn pedestal information (Locations, spacing, etc)
 * - Handles teams and its settings.
 * - Holds config data and stores them into a set of variables.
 * - Holds player information/data consistently
 * - Handles reset/shutdown properly.
 *
 * @package larryTheCoder\arenaRewrite
 */
class Arena {
	use PlayerHandler;
	use ArenaData;

	/*** @var SkyWarsPE */
	private $plugin;
	/** @var int */
	private $arenaStatus = State::STATE_WAITING;

	/** @var GameAPI */
	public $gameAPI;
	/** @var array */
	public $data;
	/** @var float */
	public $startedTime = 0;

	/** @var CageHandler */
	public $cageHandler;

	/** @var TaskHandler[] */
	private $taskRunning = [];

	/** @var GameDebugger */
	public $gameDebugger = null;

	public function getDebugger(): ?GameDebugger{
		return $this->gameDebugger;
	}

	public function __construct(string $arenaName, SkyWarsPE $plugin){
		$this->arenaName = $arenaName;
		$this->plugin = $plugin;

		try{
			$time = new DateTime('now', new \DateTimeZone(Timezone::get()));
		}catch(\Exception $e){
			throw new \RuntimeException($e);
		}

		$this->gameDebugger = new GameDebugger(SkyWarsPE::$instance->getDataFolder() . "logs/" . $time->format("Y-m-d") . " {$this->getArenaName()}.txt", $time);
		SkyWarsPE::registerDebugger($arenaName, $this->gameDebugger);

		$this->reloadData();
	}

	/**
	 * Reloads the game information of this arena.
	 */
	public function reloadData(){
		$this->getDebugger()->log("[Arena]: reloadData() function executed");
		$this->data = SkyWarsPE::getInstance()->getArenaManager()->getArenaConfig($this->arenaName)->getAll();

		$this->parseData();
		$this->configTeam($this->getArenaData());
	}

	/**
	 * Start this arena and set the arena state.
	 */
	public function startGame(){
		$this->getDebugger()->log("[Arena]: startGame() function executed");
		$this->gameAPI->startArena();

		$this->startedTime = microtime(true);
		$this->setStatus(State::STATE_ARENA_RUNNING);
		$this->messageArenaPlayers('arena-start', false);
	}

	/**
	 * Stop this arena and set the arena state.
	 */
	public function stopGame(){
		$this->getDebugger()->log("[Arena]: Stop game called");

		$this->gameAPI->stopArena();
		$this->unsetAllPlayers();

		$this->resetArena();
		$this->resetArenaWorld();
		$this->setStatus(State::STATE_WAITING);
	}

	/**
	 * @return Level|null
	 */
	public function getLevel(): ?Level{
		if($this->levelBusy) return null; // Avoid some unwanted world generation.

		Utils::loadFirst($this->arenaWorld, true);

		$level = Server::getInstance()->getLevelByName($this->arenaWorld);
		$level->setAutoSave(false);

		return $level;
	}

	/**
	 * This function is used to handle player deaths or 'knocked outs'.
	 * It is to make sure that this player will be removed from the game correctly
	 * according to what is configured in the config file.
	 *
	 * @param PlayerDeathEvent $ev
	 */
	public function knockedOut(PlayerDeathEvent $ev){
		$pl = $ev->getPlayer();

		$this->getDebugger()->log("[Arena]: {$pl->getName()} is knocked out in the game");

		// Remove the player from the list.
		$this->removePlayer($pl);

		if($this->enableSpectator){
			$this->setSpectator($pl);

			$this->getDebugger()->log("[Arena]: Spectator mode is enabled for the user");
		}elseif($this->spectateWaiting > 0){
			$this->plugin->getScheduler()->scheduleDelayedTask(new PlayerDeathTask($this, $pl), 10);

			$this->getDebugger()->log("[Arena]: Scheduling death task for the player");
		}else{
			$this->leaveArena($pl);

			$this->getDebugger()->log("[Arena]: Player is leaving the arena now");
		}
	}

	/**
	 * Forcefully reset the arena to its original state.
	 */
	public function resetArena(){
		$this->getDebugger()->log("[Arena]: Force reset to original state...");

		$this->loadCageHandler();
		$this->saveArenaWorld();
		$this->resetPlayers();

		$this->startedTime = 0;

		if($this->gameAPI === null) $this->gameAPI = new DefaultGameAPI($this);

		// Remove the task first.
		/** @var Task[] $tasks */
		$tasks = $this->gameAPI->getRuntimeTasks();
		if(!empty($this->taskRunning)){
			foreach($this->taskRunning as $id => $data){
				SkyWarsPE::getInstance()->getScheduler()->cancelTask($id);

				unset($this->taskRunning[$id]);
			}
		}

		// Then commit re-run.
		foreach($tasks as $task){
			$this->getDebugger()->log("Scheduling task {$task->getName()}.");

			$runnable = SkyWarsPE::getInstance()->getScheduler()->scheduleRepeatingTask($task, 20);
			$this->taskRunning[] = $runnable;
		}
	}

	/**
	 * Shutdown the arena forcefully.
	 */
	public function forceShutdown(){
		$this->stopGame();
		$this->gameAPI->shutdown();

		$this->unsetAllPlayers();

		if(!empty($this->taskRunning)){
			foreach($this->taskRunning as $id => $data){
				$data->cancel();

				unset($this->taskRunning[$id]);
			}
		}
	}

	/**
	 * Set the arena data. This doesn't reset the arena settings.
	 *
	 * @param Config $config
	 *
	 * @since 3.0
	 */
	public function setData(Config $config){
		$this->data = $config->getAll();
	}

	/**
	 * Return the name given for this arena.
	 *
	 * @return string
	 * @since 3.0
	 */
	public function getArenaName(): string{
		return $this->arenaName;
	}

	private $levelBusy = false;

	/**
	 * Reset the arena to its last state. In this function, the arena world will be reset and
	 * the variables will be set to its original values.
	 *
	 * @since 3.0
	 */
	public function resetArenaWorld(){
		if($this->levelBusy) return;

		$this->getDebugger()->log("[Arena]: Final state: Reset Arena...");

		if($this->plugin->getServer()->isLevelLoaded($this->arenaWorld)){
			$this->plugin->getServer()->unloadLevel($this->plugin->getServer()->getLevelByName($this->arenaWorld));
		}

		$fromPath = $this->plugin->getDataFolder() . 'arenas/worlds/' . $this->arenaWorld . ".zip";
		$toPath = $this->plugin->getServer()->getDataPath() . "worlds/" . $this->arenaWorld;

		// Reverted from diff 65e8fb78
		Utils::deleteDirectory($toPath);

		if(is_file($this->plugin->getDataFolder() . 'arenas/worlds/')){
			return;
		}
		if(!file_exists($toPath)) @mkdir($toPath, 0755);

		$this->levelBusy = true;

		SkyWarsPE::$instance->compressor->scheduleForFile($fromPath, $toPath, false, function(){
			$this->levelBusy = false;

			$this->arenaLevel = $this->getLevel();
		});
	}

	/**
	 * Get the current status for the arena
	 *
	 * @return string
	 */
	public function getReadableStatus(): string{
		switch(true){
			case $this->inSetup:
				return "&eIn setup";
			case !$this->arenaEnable:
				return "&cDisabled";
			case $this->getStatus() <= State::STATE_SLOPE_WAITING:
				return "&6Click to join!";
			case $this->getPlayers() >= $this->minimumPlayers:
				return "&6Starting";
			case $this->getStatus() === State::STATE_ARENA_RUNNING:
				return "&cRunning";
			case $this->getStatus() === State::STATE_ARENA_CELEBRATING:
				return "&cEnded";
		}

		return "&eUnknown";
	}

	/**
	 * Returns the data of the arena.
	 *
	 * @return array
	 * @since 3.0
	 */
	public function getArenaData(){
		return $this->data;
	}

	/**
	 * Add the player to join into the arena.
	 *
	 * @param Player $pl
	 *
	 * @since 3.0
	 */
	public function joinToArena(Player $pl){
		if($this->inSetup){
			$pl->sendMessage($this->plugin->getMsg($pl, 'arena-insetup'));

			return;
		}
		// Maximum players reached furthermore player can't join.
		if(count($this->getPlayers()) >= $this->maximumPlayers){
			$pl->sendMessage($this->plugin->getMsg($pl, 'arena-full'));

			return;
		}

		// Arena is in game.
		if($this->getStatus() >= State::STATE_ARENA_RUNNING){
			$pl->sendMessage($this->plugin->getMsg($pl, 'arena-running'));

			return;
		}

		// This arena is not enabled.
		if(!$this->arenaEnable){
			$pl->sendMessage($this->plugin->getMsg($pl, 'arena-disabled'));

			return;
		}

		Utils::loadFirst($this->arenaWorld); # load the level
		$this->arenaLevel = $this->plugin->getServer()->getLevelByName($this->arenaWorld); # Reset the current state of level

		// Pick one of the cages in the arena.
		/** @var Vector3 $spawnPos */
		$spawnPos = $this->cageHandler->nextCage($pl);

		// Here you can see, the code passes to the game API to check
		// If its allowed to enter the arena or not.
		if(!$this->gameAPI->joinToArena($pl, $spawnPos)){
			$this->cageHandler->removeCage($pl);

			return;
		}
		$this->kills[$pl->getName()] = 0;

		$pl->getInventory()->setHeldItemIndex(1, true);
		$this->addPlayer($pl, $this->getRandomTeam());

		$pl->teleport(Position::fromObject($spawnPos->add(0.5, 0, 0.5), $this->getLevel()));
	}

	/**
	 * Leave a player from an arena.
	 *
	 * @param Player $pl
	 * @param bool $force
	 *
	 * @since 3.0
	 */
	public function leaveArena(Player $pl, bool $force = false){
		$this->getDebugger()->log("{$pl->getName()} is leaving in arena" . ($force ? " by force." : "."));

		if(!$this->gameAPI->leaveArena($pl, $force)){
			return;
		}

		$this->getDebugger()->log("Leave condition is fulfilled");

		$this->removePlayer($pl);

		// Remove the spawn pedestals
		$this->cageHandler->removeCage($pl);

		SkyWarsPE::getInstance()->getDatabase()->teleportLobby($pl);

		unset($this->kills[$pl->getName()]);
	}

	/**
	 * Remove all of the players in this game.
	 */
	public function unsetAllPlayers(){
		$this->gameAPI->removeAllPlayers();
		$this->executeCommands();

		$this->resetPlayers();
	}

	private function executeCommands(){
		// TODO
	}

	public function checkAlive(){
		if(count($this->getPlayers()) === 1 and $this->getStatus() === State::STATE_ARENA_RUNNING){
			$this->setStatus(State::STATE_ARENA_CELEBRATING);
			foreach($this->getPlayers() as $player){
				$player->setXpLevel(0);
				$player->removeAllEffects();
				$player->setGamemode(0);
				$player->getInventory()->clearAll();
				$player->getArmorInventory()->clearAll();
				$player->setGamemode(Player::SPECTATOR);
				$this->giveGameItems($player, true);
			}
		}elseif(empty($this->getPlayers()) && ($this->getStatus() !== State::STATE_SLOPE_WAITING && $this->getStatus() !== State::STATE_WAITING)){
			$this->stopGame();
		}
	}

	/**
	 * Get the status of the arena. Please use the constants that is
	 * set in this class to check what is the status.
	 *
	 * @return int
	 * @since 3.0
	 */
	public function getStatus(){
		return $this->arenaStatus;
	}

	/**
	 * Set the status of the arena.
	 *
	 * @param int $statusCode
	 *
	 * @since 3.0
	 */
	public function setStatus(int $statusCode){
		$this->getDebugger()->log("[GameStatusChange]: $statusCode");

		$this->arenaStatus = $statusCode;
	}

	private function loadCageHandler(){
		if($this->cageHandler === null) $this->cageHandler = new CageHandler($this->spawnPedestals);

		$this->cageHandler->resetAll();
	}

	private function getRandomTeam(): int{
		if(!$this->teamMode){
			return -1;
		}

		// Get the lowest members in a team
		// And use them as the player team
		asort($this->configuredTeam);

		foreach($this->configuredTeam as $colour){
			return $colour;
		}

		$this->getDebugger()->log("[Arena]: Configured team is empty?");

		return -1;
	}

	private function configTeam(array $data){
		if(!isset($data['arena-mode']) || $data['arena-mode'] == State::MODE_SOLO){
			return;
		}
		$this->getDebugger()->log("Parsing configTeam().");

		// The colours of the wool
		// [See I use color instead of colours? Blame British English]
		$colors = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];
		for($color = 0; $color <= 15; $color++){
			if($color >= ($this->maximumTeams - 1)){
				break;
			}

			$randColor = array_rand($colors);
			$this->configuredTeam[$colors[$randColor]] = 0;

			unset($colors[$randColor]);
		}
	}

	public function saveArenaWorld(){
		$levelName = $this->arenaWorld;

		$fromPath = $this->plugin->getServer()->getDataPath() . "worlds/" . $levelName;
		$toPath = $this->plugin->getDataFolder() . 'arenas/worlds/' . $levelName . ".zip";

		// Reverted from diff 65e8fb78
		Utils::ensureDirectory($this->plugin->getDataFolder() . 'arenas/worlds/');

		// If the fie exists, then reset the world.
		if(is_file("$toPath")){
			$this->resetArenaWorld();

			return;
		}

		$this->levelBusy = true;

		SkyWarsPE::$instance->compressor->scheduleForFile($fromPath, $toPath, true, function(){
			$this->levelBusy = false;

			$this->arenaLevel = $this->getLevel();
		});
	}

	public function performEdit(int $state){
		if($state === State::STARTING){
			Utils::deleteDirectory($this->plugin->getDataFolder() . 'arenas/worlds/' . $this->arenaWorld . ".zip");
		}else{
			$this->saveArenaWorld();
		}
	}

}