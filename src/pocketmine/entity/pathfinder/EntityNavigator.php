<?php

/*
 *               _ _
 *         /\   | | |
 *        /  \  | | |_ __ _ _   _
 *       / /\ \ | | __/ _` | | | |
 *      / ____ \| | || (_| | |_| |
 *     /_/    \_|_|\__\__,_|\__, |
 *                           __/ |
 *                          |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author TuranicTeam
 * @link https://github.com/TuranicTeam/Altay
 *
 */

declare(strict_types=1);

namespace pocketmine\entity\pathfinder;

use pocketmine\block\Block;
use pocketmine\block\Lava;
use pocketmine\block\Liquid;
use pocketmine\block\Water;
use pocketmine\entity\Attribute;
use pocketmine\entity\Mob;
use pocketmine\math\Facing;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;

class EntityNavigator{
	public const PROCESSOR_TYPE_SWIM = 0;
	public const PROCESSOR_TYPE_WALK = 1;

	/** @var Mob */
	protected $mob;

	protected $neighbors = [
		[
			0,
			-1
		],
		[
			1,
			0
		],
		[
			0,
			1
		],
		[
			-1,
			0
		],
		[
			-1,
			-1
		],
		[
			1,
			-1
		],
		[
			1,
			1
		],
		[
			-1,
			1
		]
	];

	/** @var Path */
	protected $currentPath;
	/** @var bool */
	protected $avoidsWater = false, $avoidsSun = false;
	/** @var float */
	protected $speedMultiplier = 1.0;

	protected $lastPoint = null;
	protected $stuckTick = 0;
	/** @var Vector3 */
	protected $movePoint;
	/** @var int */
	protected $processorType = self::PROCESSOR_TYPE_WALK;

	public function __construct(Mob $mob){
		$this->mob = $mob;
	}

	/**
	 * @param PathPoint  $from
	 * @param PathPoint  $to
	 * @param float|null $followRange
	 *
	 * @return array
	 */
	public function navigate(PathPoint $from, PathPoint $to, ?float $followRange = null) : array{
		if($followRange === null){
			$followRange = $this->mob->getFollowRange();
		}
		if($followRange <= 0){
			$followRange = 20; //base
		}
		$blockCache = [];
		$ticks = 0;
		$from->fScore = $this->calculateGridDistance($from, $to);
		$last = $from;
		$path = [];
		$open = [$from->getHashCode() => $from];
		$currentY = $this->getUsableYCoordinate();
		$closed = [];
		$highScore = $from;

		while(!empty($open)){
			$current = $last;
			if($last !== $highScore){
				uasort($open, function($a, $b){
					if($a->fScore == $b->fScore) return 0;

					return $a->fScore > $b->fScore ? 1 : -1;
				});
				$current = reset($open);
				$currentY = $this->getBlockByPoint($current, $blockCache)->y;
			}

			$last = null;

			if($current->equals($to)){
				return $this->initPath($path, $current);
			}
			if($ticks++ > $followRange * 2){
				return $this->initPath($path, $highScore);
			}

			unset($open[$current->getHashCode()]);
			$closed[$current->getHashCode()] = $current;

			foreach($this->getNeighbors($current, $blockCache, $currentY) as $n){
				if(!isset($closed[$n->getHashCode()])){
					$g = $current->gScore + $this->calculateBlockDistance($current, $n, $blockCache);
					if($g >= $followRange){
						return $this->initPath($path, $highScore);
					}

					if(isset($open[$n->getHashCode()])){
						$og = $open[$n->getHashCode()];
						if($g >= $og->gScore) continue;
					}
					$open[$n->getHashCode()] = $n;
					$path[$n->getHashCode()] = $current;

					$n->gScore = $g;
					$n->fScore = $g + $this->calculateGridDistance($n, $to);

					if($n->fScore <= $highScore->fScore){
						$highScore = $n;
						$last = $n;
					}
				}
			}
			if($last !== null){
				$currentY = $this->getBlockByPoint($last, $blockCache)->y;
			}
		}

		return [];
	}

	/**
	 * @return int
	 */
	public function getUsableYCoordinate() : int{
		switch($this->processorType){
			case self::PROCESSOR_TYPE_WALK:
				$y = $this->mob->getFloorY();

				if($this->mob->isSwimmer()){
					$currentBlock = $this->mob->level->getBlock($this->mob);
					while($currentBlock instanceof Water){
						$currentBlock = $currentBlock->getSide(Facing::UP);
						$y++;
					}
				}
				return $y;

			default:
				return $this->mob->getFloorY();
		}
	}

	/**
	 * @param array     $path
	 * @param PathPoint $current
	 *
	 * @return array
	 */
	public function initPath(array $path, PathPoint $current){
		$totalPath = [$current];
		while(isset($path[$current->getHashCode()])){
			$current = $path[$current->getHashCode()];
			array_unshift($totalPath, $current);
		}
		unset($totalPath[0]);
		return array_values($totalPath);
	}

	/**
	 * @param PathPoint $from
	 * @param PathPoint $to
	 *
	 * @return float
	 */
	public function calculateGridDistance(PathPoint $from, PathPoint $to) : float{
		return abs($from->x - $to->x) + abs($from->y - $to->y);
	}

	/**
	 * @param PathPoint $from
	 * @param PathPoint $to
	 * @param array     $cache
	 *
	 * @return float
	 */
	public function calculateBlockDistance(PathPoint $from, PathPoint $to, array $cache) : float{
		$block1 = $this->getBlockByPoint($from, $cache);
		$block2 = $this->getBlockByPoint($to, $cache);

		if($block1 === null or $block2 === null){
			return 0;
		}

		if($this->mob->canClimb()){
			$block1->y = $block2->y = 0;
		}

		return $block1->distanceSquared($block2);
	}

	/**
	 * @param PathPoint $tile
	 * @param array     $cache
	 *
	 * @return null|Block
	 */
	public function getBlockByPoint(PathPoint $tile, array $cache) : ?Block{
		return $cache[$tile->getHashCode()] ?? null;
	}

	/**
	 * @param PathPoint $tile
	 * @param array     $cache
	 * @param int       $startY
	 *
	 * @return Vector2[]
	 */
	public function getNeighbors(PathPoint $tile, array &$cache, int $startY) : array{
		$block = $this->mob->level->getBlock(new Vector3($tile->x, $startY, $tile->y));

		if(!isset($cache[$tile->getHashCode()])){
			$cache[$tile->getHashCode()] = $block;
		}

		$list = [];
		for($index = 0; $index < count($this->neighbors); ++$index){
			$item = new PathPoint($tile->x + $this->neighbors[$index][0], $tile->y + $this->neighbors[$index][1]);
			// Check for too high steps

			$coord = new Vector3((int) $item->x, $block->y, (int) $item->y);
			if($this->mob->level->getBlock($coord)->isSolid()){
				if($this->mob->canClimb() or $this->mob->isSwimmer()){
					$blockUp = $this->mob->level->getBlock($coord->getSide(Facing::UP));
					$canMove = false;
					for($i = 0; $i < 10; $i++){
						if($this->isBlocked($blockUp->asVector3())){
							$blockUp = $this->mob->level->getBlock($blockUp->getSide(Facing::UP));
							continue;
						}

						$canMove = true;
						break;
					}

					if(!$canMove or $this->isObstructed($blockUp)) continue;

					$cache[$item->getHashCode()] = $blockUp;
				}else{
					$blockUp = $this->mob->level->getBlock($coord->getSide(Facing::UP));
					if($blockUp->isSolid()){
						// Can't jump
						continue;
					}

					if($this->isObstructed($blockUp)) continue;

					$cache[$item->getHashCode()] = $blockUp;
				}
			}else{
				$blockDown = $this->mob->level->getBlock($coord->add(0, -1, 0));
				if(!$blockDown->isSolid() and !$this->mob->isSwimmer() and !($blockDown instanceof Liquid)){ // TODO: bug?
					if($this->mob->canClimb()){
						$canClimb = false;
						$blockDown = $this->mob->level->getBlock($blockDown->getSide(Facing::DOWN));
						for($i = 0; $i < 10; $i++){
							if(!$blockDown->isSolid()){
								$blockDown = $this->mob->level->getBlock($blockDown->add(0, -1, 0));
								continue;
							}

							$canClimb = true;
							break;
						}

						if(!$canClimb) continue;

						$blockDown = $this->mob->level->getBlock($blockDown->getSide(Facing::UP));

						if($this->isObstructed($blockDown)) continue;

						$cache[$item->getHashCode()] = $blockDown;
					}else{
						if(!$this->mob->level->getBlock($coord->getSide(Facing::DOWN, 2))->isSolid()){
							// Will fall
							continue;
						}

						if($this->isObstructed($blockDown)) continue;

						$cache[$item->getHashCode()] = $blockDown;
					}
				}else{
					if($this->isObstructed($coord) or (!$this->mob->isSwimmer() and $this->avoidsWater and $this->mob->level->getBlock($coord->getSide(Facing::DOWN)) instanceof Liquid)) continue;


					$cache[$item->getHashCode()] = $this->mob->level->getBlock($coord);
				}
			}
			$item->height = $cache[$item->getHashCode()]->y;
			$list[$index] = $item;
		}
		$this->checkDiagonals($list);
		return $list;
	}

	/**
	 * @param array $list
	 */
	public function checkDiagonals(array &$list) : void{
		$checkDiagonals = [
			0 => [
				4,
				5
			],
			1 => [
				5,
				6
			],
			2 => [
				6,
				7
			],
			3 => [
				4,
				7
			]
		];

		foreach($checkDiagonals as $index => $diagonal){
			if(!isset($list[$index])){
				foreach($diagonal as $dia){
					unset($list[$dia]);
				}
			}
		}
	}

	/**
	 * @param Vector3 $coord
	 *
	 * @return bool
	 */
	public function isObstructed(Vector3 $coord) : bool{
		for($i = 1; $i < $this->mob->height; $i++) if($this->isBlocked($coord->add(0, $i, 0))) return true;

		return false;
	}

	/**
	 * @param Vector3 $coord
	 *
	 * @return bool
	 */
	public function isBlocked(Vector3 $coord) : bool{
		$block = $this->mob->level->getBlock($coord);
		return $block->isSolid();
	}

	/**
	 * Removes sunny path from current path
	 */
	public function removeSunnyPath() : void{
		if($this->avoidsSun and $this->mob->level->isDayTime()){
			$temp = new Vector3();
			foreach($this->currentPath->getPoints() as $i => $point){
				if($this->mob->level->canSeeSky($temp->setComponents($point->x, $point->height, $point->y))){
					$this->currentPath->limitPath($i - 1);
					return;
				}
			}
		}
	}

	/**
	 * Follows current path by index
	 */
	public function pathFollow() : void{
		if($this->currentPath !== null){
			$length = count($this->currentPath->getPoints()) - 1;

			for($i = $this->currentPath->getCurrentIndex(); $i < count($this->currentPath->getPoints()); ++$i){
				if($this->currentPath->getPointByIndex($i)->height != (int) floor($this->mob->y)){
					$length = $i;
					break;
				}
			}

			$currentPoint = $this->currentPath->getPointByIndex($this->currentPath->getCurrentIndex());
			if(floor($this->mob->x) == $currentPoint->x and floor($this->mob->z) == $currentPoint->y){
				$this->currentPath->setCurrentIndex($this->currentPath->getCurrentIndex() + 1);
			}

			for($a = $length; $a >= $this->currentPath->getCurrentIndex(); --$a){
				$vec = $this->currentPath->getVectorByIndex($a);
				$vec->y = floor($this->mob->y);
				if($this->isClearBetweenPoints($this->mob->floor(), $vec)){
					$this->currentPath->setCurrentIndex($a);
					break;
				}
			}
		}
	}

	/**
	 * @param Vector3 $from
	 * @param Vector3 $to
	 * @param bool    $onlySee
	 *
	 * @return bool
	 */
	public function isClearBetweenPoints(Vector3 $from, Vector3 $to, bool $onlySee = false) : bool{
		$entityPos = $from;
		$targetPos = $to;
		$distance = $entityPos->distance($targetPos);
		$rayPos = $entityPos;
		$direction = $targetPos->subtract($entityPos)->normalize();

		if($distance < $direction->length()){
			return true;
		}

		do{
			if(!$this->isSafeToStandAt($rayPos->floor(), $onlySee)){
				return false;
			}
			$rayPos = $rayPos->add($direction);
		}while($distance > $entityPos->distance($rayPos));

		return true;
	}

	/**
	 * @param Vector3 $pos
	 * @param bool    $onlySee
	 *
	 * @return bool
	 */
	public function isSafeToStandAt(Vector3 $pos, bool $onlySee = false) : bool{
		if($this->isObstructed($pos) and !$onlySee){
			return false;
		}elseif($this->isBlocked($pos)){
			return false;
		}elseif(!$onlySee){
			$block = $this->mob->level->getBlockAt($pos->x, $pos->y - 1, $pos->z);
			return !(($block instanceof Water and !$this->mob->isSwimmer() and $this->avoidsWater) or $block instanceof Lava);
		}
		return true;
	}

	/**
	 * @param Path $path
	 */
	public function setPath(Path $path) : void{
		$this->currentPath = $path;
		$this->removeSunnyPath();
	}

	/**
	 * @return null|Path
	 */
	public function getPath() : ?Path{
		return $this->currentPath;
	}

	/**
	 * @return bool
	 */
	public function havePath() : bool{
		return $this->currentPath !== null and $this->currentPath->havePath();
	}

	/**
	 * @return bool
	 */
	public function isBusy() : bool{
		return $this->havePath() or $this->movePoint !== null;
	}

	/**
	 * @param bool $all
	 */
	public function clearPath(bool $all = true) : void{
		$this->currentPath = null;
		$this->lastPoint = null;
		$this->stuckTick = 0;
		if($all){
			$this->movePoint = null;
			$this->mob->getAttributeMap()->getAttribute(Attribute::MOVEMENT_SPEED)->resetToDefault();
		}
	}

	/**
	 * @param bool $value
	 */
	public function setAvoidsWater(bool $value) : void{
		$this->avoidsWater = $value;
	}

	/**
	 * @param bool $value
	 */
	public function setAvoidsSun(bool $value) : void{
		$this->avoidsSun = $value;
	}

	/**
	 * @return bool
	 */
	public function getAvoidsWater() : bool{
		return $this->avoidsWater;
	}

	/**
	 * @return bool
	 */
	public function getAvoidsSun() : bool{
		return $this->avoidsSun;
	}

	/**
	 * @param Vector3 $point
	 *
	 * @return bool
	 */
	public function isSameDestination(Vector3 $point) : bool{
		return !$this->havePath() ? false : $this->currentPath->getVectorByIndex(count($this->currentPath->getPoints()) - 1)->equals($point);
	}

	/**
	 * @param Vector3    $pos
	 * @param float      $speed
	 * @param float|null $followRange
	 *
	 * @return bool
	 */
	public function tryMoveTo(Vector3 $pos, float $speed, ?float $followRange = null) : bool{
		if(!$this->isSameDestination($pos->floor())){
			$this->setSpeedMultiplier($speed);
			$this->setPath($this->findPath($pos, $followRange));
			return true;
		}
		return false;
	}

	/**
	 * @param Vector3    $pos
	 * @param float|null $followRange
	 *
	 * @return Path
	 */
	public function findPath(Vector3 $pos, ?float $followRange = null) : Path{
		return new Path($this->navigate(new PathPoint(floor($this->mob->x), floor($this->mob->z)), new PathPoint(floor($pos->x), floor($pos->z)), $followRange));
	}

	/**
	 * Update for navigation movement
	 */
	public function onNavigateUpdate() : void{
		if($this->currentPath !== null){
			if($this->havePath()){
				$this->pathFollow();
				$next = $this->currentPath->getPointByIndex($this->currentPath->getCurrentIndex());
				if($next !== null){
					$this->movePoint = $next;
				}else{
					$this->clearPath(false);
				}
			}else{
				$this->clearPath(false);
			}
		}

		if($this->movePoint !== null){
			$this->mob->lookAt(new Vector3($this->movePoint->x + 0.5, $this->mob->y, $this->movePoint->y + 0.5));
			if(!$this->mob->moveForward($this->speedMultiplier)){
				$this->clearPath();
				return;
			}

			$currentPos = $this->mob->floor();

			if($currentPos->x == $this->movePoint->x and $currentPos->z == $this->movePoint->y){
				$this->movePoint = null;
			}

			if($currentPos === $this->lastPoint){
				$this->stuckTick++;

				if($this->stuckTick > 100){
					$this->clearPath();
				}
			}else{
				$this->lastPoint = $currentPos;
				$this->stuckTick = 0;
			}
		}
	}

	/**
	 * @return float
	 */
	public function getSpeedMultiplier() : float{
		return $this->speedMultiplier;
	}

	/**
	 * @param float $speedMultiplier
	 */
	public function setSpeedMultiplier(float $speedMultiplier) : void{
		$this->speedMultiplier = $speedMultiplier;
		$this->mob->setMovementSpeed($this->mob->getDefaultMovementSpeed() * $this->speedMultiplier);
	}

	/**
	 * @return int
	 */
	public function getProcessorType() : int{
		return $this->processorType;
	}

	/**
	 * @param int $processorType
	 */
	public function setProcessorType(int $processorType) : void{
		$this->processorType = $processorType;
	}

}