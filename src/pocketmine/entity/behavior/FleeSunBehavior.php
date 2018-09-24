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

namespace pocketmine\entity\behavior;

use pocketmine\block\Block;
use pocketmine\entity\Entity;
use pocketmine\entity\Mob;
use pocketmine\math\Vector3;
use pocketmine\block\Water;

class FleeSunBehavior extends Behavior{
	/** @var float */
	protected $speedMultiplier = 1.0;
	/** @var Vector3 */
	protected $shelter;

	public function __construct(Mob $mob, float $speedMultiplier = 1.0){
		parent::__construct($mob);

		$this->speedMultiplier = $speedMultiplier;
		$this->mutexBits = 1;
	}

	public function canStart() : bool{
		if($this->mob->isOnFire() and $this->mob->level->isDayTime() and $this->mob->level->canSeeSky($this->mob->floor())){
			$this->shelter = $this->findPossibleShelter($this->mob);

			return $this->shelter !== null;
		}

		return false;
	}

	public function onStart() : void{
		$this->mob->getNavigator()->tryMoveTo($this->shelter, $this->speedMultiplier);
	}

	public function canContinue() : bool{
		return $this->mob->getNavigator()->isBusy();
	}

	public function onEnd() : void{
		$this->mob->resetMotion();
		$this->mob->getNavigator()->clearPath();
	}

	public function findPossibleShelter(Entity $entity) : ?Block{
		for($i = 0; $i < 10; $i++){
		    $pos        = $this->mob->add($this->random->nextBoundedInt(20) - 10);
		    $cached     = $this->random->nextBoundedInt(6) - 3;
		    $addToCache = $this->random->nextBoundedInt(20) - 10;

			$block      = $this->mob->level->getBlock($pos, $cached, $addToCache);
			$canSeeSky  = $this->mob->level->canSeeSky($block);

			if(!$block->isSolid() and ($block instanceof Water or !$canSeeSky)){
				return $block;
			}
		}

		return null;
	}
}